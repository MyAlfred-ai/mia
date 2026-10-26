<?php

declare(strict_types=1);

/**
 * Ce que regarde l'écran des journaux : période, gravités, domaine,
 * recherche, problème (signature) ou incident, vue et tri. Pure.
 *
 * Paramètres de l'adresse (identifiants en français, quelle que soit la langue
 * de l'écran — seuls les libellés affichés se traduisent, par les accesseurs) :
 *   vue=problemes|flux, periode=1h|6h|24h|7j|30j|tout|perso, du=…, au=…,
 *   niveaux=critical,error,…|tous, domaine=…, q=…, sig=…, incident=…,
 *   tri=gravite|frequence|recent.
 * Les adresses de l'ancienne page (`type`, `from`, `to`) restent comprises.
 *
 * Les dates d'un intervalle personnalisé sont lues dans le FUSEAU DE LA
 * PERSONNE (posé par le navigateur) : le serveur tourne en UTC, et
 * « aujourd'hui depuis minuit » saisi à Paris tombait deux heures à côté.
 */
final class LogQuery
{
    /** Périodes proposées → durée en secondes (null : tout le journal analysé). */
    public const PERIODS = [
        '1h'   => 3600,
        '6h'   => 21600,
        '24h'  => 86400,
        '7j'   => 604800,
        '30j'  => 2592000,
        'tout' => null,
    ];

    public const PERIOD_LABELS = [
        '1h' => '1 h', '6h' => '6 h', '24h' => '24 h', '7j' => '7 jours', '30j' => '30 jours', 'tout' => 'Tout', 'perso' => 'Personnalisé',
    ];

    /** « … sur les dernières 24 h » : la période dans une phrase. */
    public const SPAN_LABELS = [
        '1h' => 'la dernière heure', '6h' => 'les 6 dernières heures', '24h' => 'les dernières 24 h',
        '7j' => 'les 7 derniers jours', '30j' => 'les 30 derniers jours', 'tout' => 'tout le journal analysé',
        'perso' => 'la période choisie',
    ];

    public const DEFAULT_PERIOD = '24h';

    public const VIEWS = ['problemes' => 'Problèmes', 'flux' => 'Flux'];

    public const SORTS = ['gravite' => 'Gravité', 'recent' => 'Plus récents', 'frequence' => 'Plus fréquents'];

    /**
     * Libellés AFFICHÉS, traduits. Les constantes ci-dessus ne peuvent pas
     * appeler t() : leur texte français est la clé, traduite ici.
     */
    public static function periodLabel(string $period): string
    {
        $label = self::PERIOD_LABELS[$period] ?? null;
        return $label !== null ? t($label) : $period;
    }

    /** La période dans une phrase (« … sur les dernières 24 h »), traduite. */
    public static function spanLabel(string $period): string
    {
        return t(self::SPAN_LABELS[$period] ?? self::SPAN_LABELS['perso']);
    }

    public static function sortLabel(string $sort): string
    {
        $label = self::SORTS[$sort] ?? null;
        return $label !== null ? t($label) : $sort;
    }

    public const SEARCH_MAX = 200;

    public string $view = 'problemes';
    public string $period = self::DEFAULT_PERIOD;
    public int $from = 0;
    public int $to = 0;
    /** @var list<string> */
    public array $levels = LogCatalog::DEFAULT_LEVELS;
    public ?string $source = null;
    public string $search = '';
    /** @var list<string> termes à trouver (minuscules) */
    public array $terms = [];
    /** @var list<string> termes à exclure (minuscules) */
    public array $excludes = [];
    public ?string $sig = null;
    public ?string $incident = null;
    public string $sort = 'gravite';
    public \DateTimeZone $tz;
    /** Valeurs des champs « Du » / « Au » (format datetime-local, dans le fuseau). */
    public string $fromInput = '';
    public string $toInput = '';

    private function __construct()
    {
        $this->tz = new \DateTimeZone('UTC');
    }

    /**
     * @param array<string,mixed> $get       paramètres de l'adresse
     * @param string|null         $tzName    fuseau du navigateur (cookie)
     * @param int                 $now       maintenant (Unix)
     * @param int|null            $firstTs   première entrée lisible du journal analysé
     */
    public static function fromRequest(array $get, ?string $tzName, int $now, ?int $firstTs): self
    {
        $q = new self();
        $q->tz = self::timeZone(is_string($get['tz'] ?? null) ? (string)$get['tz'] : $tzName);

        $view = (string)($get['vue'] ?? '');
        $q->view = isset(self::VIEWS[$view]) ? $view : 'problemes';

        $sort = (string)($get['tri'] ?? '');
        $q->sort = isset(self::SORTS[$sort]) ? $sort : 'gravite';

        // Recherche : un identifiant d'incident collé tel quel devient le filtre incident.
        $search = trim(mb_substr((string)($get['q'] ?? ''), 0, self::SEARCH_MAX));
        $incident = strtolower(trim((string)($get['incident'] ?? '')));
        if ($incident === '' && LogCatalog::isIncidentId(strtolower($search))) {
            $incident = strtolower($search);
            $search = '';
        }
        $q->incident = LogCatalog::isIncidentId($incident) ? $incident : null;
        $q->search = $search;
        [$q->terms, $q->excludes] = self::parseSearch($search);

        if ($q->incident !== null && !isset(self::VIEWS[$view])) {
            // Un incident recherché, c'est UNE panne à lire : directement dans le Flux.
            $q->view = 'flux';
        }

        $sig = strtolower(trim((string)($get['sig'] ?? '')));
        $q->sig = LogCatalog::isFingerprint($sig) ? $sig : null;

        // Domaine (et ancien `type` de l'ancienne page).
        $legacyType = (string)($get['type'] ?? '');
        $source = (string)($get['domaine'] ?? '');
        if ($source === '') {
            $source = ['llm' => 'ia', 'kb' => 'kb', 'auth' => 'securite'][$legacyType] ?? '';
        }
        $q->source = LogCatalog::isSource($source) ? $source : null;

        // Gravités.
        $levels = (string)($get['niveaux'] ?? '');
        if ($levels === '' && in_array($legacyType, ['error', 'warning'], true)) {
            $levels = $legacyType === 'error' ? 'critical,error' : 'warning';
        }
        $q->levels = self::parseLevels($levels);

        // Période.
        $period  = (string)($get['periode'] ?? '');
        $fromRaw = trim((string)($get['du'] ?? ($get['from'] ?? '')));
        $toRaw   = trim((string)($get['au'] ?? ($get['to'] ?? '')));
        if ($period === '' && ($fromRaw !== '' || $toRaw !== '')) {
            $period = 'perso';
        }
        if ($q->incident !== null && $period !== 'perso') {
            // Un incident se cherche dans tout le journal : il date peut-être d'hier.
            $period = 'tout';
        }
        if ($period !== 'perso' && !array_key_exists($period, self::PERIODS)) {
            $period = self::DEFAULT_PERIOD;
        }
        $q->period = $period;
        $q->to = $now;

        if ($period === 'perso') {
            $from = self::parseLocal($fromRaw, $q->tz);
            $to   = self::parseLocal($toRaw, $q->tz);
            $q->to   = $to ?? $now;
            $q->from = $from ?? ($q->to - (int)self::PERIODS[self::DEFAULT_PERIOD]);
            if ($q->to < $q->from) {
                [$q->from, $q->to] = [$q->to, $q->from];
            }
        } elseif ($period === 'tout') {
            $q->from = $firstTs !== null ? min($firstTs, $now) : $now - (int)self::PERIODS['30j'];
        } else {
            $q->from = $now - (int)self::PERIODS[$period];
        }
        $q->fromInput = self::formatLocal($q->from, $q->tz);
        $q->toInput   = self::formatLocal($q->to, $q->tz);

        return $q;
    }

    /** Fuseau IANA valide, sinon UTC (celui du serveur). */
    public static function timeZone(?string $name): \DateTimeZone
    {
        $name = trim((string)$name);
        if ($name !== '' && strlen($name) <= 64 && preg_match('#^[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+){0,2}$|^UTC$#', $name) === 1) {
            try {
                return new \DateTimeZone($name);
            } catch (\Throwable) {
                // fuseau inconnu : repli
            }
        }
        return new \DateTimeZone('UTC');
    }

    /**
     * Recherche : mots (tous requis), « "expression exacte" », « -mot » à exclure.
     *
     * @return array{0:list<string>,1:list<string>}
     */
    public static function parseSearch(string $search): array
    {
        $terms = [];
        $excludes = [];
        if (trim($search) === '') {
            return [$terms, $excludes];
        }
        preg_match_all('/(-?)"([^"]+)"|(-?)(\S+)/u', $search, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $neg  = ($m[1] ?? '') === '-' || ($m[3] ?? '') === '-';
            $term = ($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? '');
            $term = mb_strtolower(trim($term));
            if ($term === '' || $term === '-') {
                continue;
            }
            if ($neg) {
                $excludes[] = $term;
            } else {
                $terms[] = $term;
            }
        }
        return [array_values(array_unique($terms)), array_values(array_unique($excludes))];
    }

    /** @return list<string> */
    public static function parseLevels(string $raw): array
    {
        $raw = strtolower(trim($raw));
        if ($raw === 'tous') {
            return array_keys(LogCatalog::LEVELS);
        }
        $levels = array_values(array_filter(
            array_keys(LogCatalog::LEVELS),
            static fn(string $l): bool => in_array($l, explode(',', $raw), true)
        ));
        return $levels !== [] ? $levels : LogCatalog::DEFAULT_LEVELS;
    }

    private static function parseLocal(string $value, \DateTimeZone $tz): ?int
    {
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $tz);
            if ($date instanceof \DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }
        return null;
    }

    public static function formatLocal(int $ts, \DateTimeZone $tz): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d\TH:i');
    }

    /**
     * L'entrée correspond-elle ? `$ignore` retire des critères (facettes :
     * les compteurs d'une gravité ignorent le filtre de gravité, etc.).
     *
     * @param array<string,mixed> $entry
     * @param list<string>        $ignore  parmi time, level, source, search, sig, incident
     */
    public function matches(array $entry, array $ignore = []): bool
    {
        if (!in_array('time', $ignore, true)) {
            $ts = $entry['ts'];
            if ($ts === null) {
                if ($this->period !== 'tout') {
                    return false;
                }
            } elseif ($ts < $this->from || $ts > $this->to) {
                return false;
            }
        }
        if (!in_array('incident', $ignore, true) && $this->incident !== null && $entry['incident'] !== $this->incident) {
            return false;
        }
        if (!in_array('sig', $ignore, true) && $this->sig !== null && $entry['sig'] !== $this->sig) {
            return false;
        }
        if (!in_array('level', $ignore, true) && !in_array($entry['level'], $this->levels, true)) {
            return false;
        }
        if (!in_array('source', $ignore, true) && $this->source !== null && $entry['source'] !== $this->source) {
            return false;
        }
        if (!in_array('search', $ignore, true) && ($this->terms !== [] || $this->excludes !== [])) {
            $hay = $entry['lower'] ?? mb_strtolower((string)$entry['raw']);
            foreach ($this->terms as $term) {
                if (!str_contains($hay, $term)) {
                    return false;
                }
            }
            foreach ($this->excludes as $term) {
                if (str_contains($hay, $term)) {
                    return false;
                }
            }
        }
        return true;
    }

    public function hasSearch(): bool
    {
        return $this->terms !== [] || $this->excludes !== [];
    }

    public function levelsAreDefault(): bool
    {
        $a = $this->levels;
        $b = LogCatalog::DEFAULT_LEVELS;
        sort($a);
        sort($b);
        return $a === $b;
    }

    /** Durée de la fenêtre, en secondes. */
    public function span(): int
    {
        return max(1, $this->to - $this->from);
    }

    /**
     * Paramètres de l'adresse, valeurs par défaut omises.
     *
     * @param array<string,mixed> $overrides  null retire le paramètre
     * @return array<string,string>
     */
    public function params(array $overrides = []): array
    {
        $levels = $this->levelsAreDefault() ? null
            : (count($this->levels) === count(LogCatalog::LEVELS) ? 'tous' : implode(',', $this->levels));
        $params = [
            'vue'      => $this->view !== 'problemes' ? $this->view : null,
            'periode'  => $this->period !== self::DEFAULT_PERIOD ? $this->period : null,
            'du'       => $this->period === 'perso' ? $this->fromInput : null,
            'au'       => $this->period === 'perso' ? $this->toInput : null,
            'niveaux'  => $levels,
            'domaine'  => $this->source,
            'q'        => $this->search !== '' ? $this->search : null,
            'sig'      => $this->sig,
            'incident' => $this->incident,
            'tri'      => $this->sort !== 'gravite' ? $this->sort : null,
        ];
        foreach ($overrides as $key => $value) {
            $params[$key] = $value === null || $value === '' ? null : (string)$value;
        }
        if (($params['periode'] ?? null) !== 'perso') {
            unset($params['du'], $params['au']);
        }
        return array_filter($params, static fn($v): bool => $v !== null && $v !== '');
    }

    /** @param array<string,mixed> $overrides */
    public function url(array $overrides = [], string $base = '/admin/logs'): string
    {
        $params = $this->params($overrides);
        return $base . ($params !== [] ? '?' . http_build_query($params) : '');
    }

    /**
     * Gravités après avoir basculé l'une d'elles (jamais aucune : retour au défaut).
     *
     * @return string|null valeur du paramètre `niveaux` (null = défaut)
     */
    public function toggledLevels(string $level): ?string
    {
        $levels = in_array($level, $this->levels, true)
            ? array_values(array_diff($this->levels, [$level]))
            : array_merge($this->levels, [$level]);
        $levels = array_values(array_filter(array_keys(LogCatalog::LEVELS), static fn($l) => in_array($l, $levels, true)));
        if ($levels === []) {
            return null;
        }
        $sortedA = $levels;
        $sortedB = LogCatalog::DEFAULT_LEVELS;
        sort($sortedA);
        sort($sortedB);
        if ($sortedA === $sortedB) {
            return null;
        }
        return count($levels) === count(LogCatalog::LEVELS) ? 'tous' : implode(',', $levels);
    }
}
