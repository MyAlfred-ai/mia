<?php

declare(strict_types=1);

/**
 * Fournisseur Google Agenda (Calendar API v3) pour la synchronisation des
 * bases de connaissances, authentifié par CLÉ API simple (pas d'OAuth) :
 * suffisant pour LIRE un agenda rendu PUBLIC (Paramètres de l'agenda →
 * « Rendre disponible publiquement »).
 *
 * Un agenda n'est pas un dossier de fichiers : le fournisseur fabrique des
 * documents VIRTUELS — un fichier markdown par MOIS d'évènements — sur une
 * fenêtre glissante alignée sur les mois (PAST_MONTHS en arrière,
 * FUTURE_MONTHS en avant). Le moteur KbSync les traite comme n'importe quel
 * fichier distant :
 *   id       = 'YYYY-MM' (stable, clé du diff)
 *   hash     = sha256 du markdown (64 hex = VARCHAR(64) sync_hash) : un
 *              évènement ajouté/modifié/supprimé change le hash → réingestion
 *   contenu  = généré au listing, servi par fetchTo depuis le cache d'instance
 * Un mois sans évènement n'émet pas de document ; un mois qui sort de la
 * fenêtre disparaît du listing → le principe miroir le retire de la base.
 * Le rendu est DÉTERMINISTE (aucun horodatage de génération, tri stable) :
 * mêmes évènements → même hash → aucun churn de cycle en cycle.
 *
 * La clé est transportée dans le header x-goog-api-key (jamais dans l'URL) :
 * les messages d'exception peuvent citer le path sans risquer de la fuiter.
 * Les corps d'erreur Google sont loggés, JAMAIS renvoyés dans l'exception.
 *
 * Piège connu : Google renvoie 404 (et non 403) sur un agenda privé accédé
 * anonymement — le message utilisateur couvre « introuvable OU non public ».
 */
class GoogleCalendarProvider implements RemoteFolderProvider
{
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    /** Agenda volontairement inexistant : sonde du test de connexion (404 attendu = clé opérationnelle). */
    private const PROBE_CALENDAR_ID = 'mia-sonde-test-inexistante@group.calendar.google.com';

    /** Fenêtre glissante, alignée sur les mois civils (contenu stable au sein d'un mois). */
    public const PAST_MONTHS   = 3;
    public const FUTURE_MONTHS = 12;

    /** Coupe une description d'évènement démesurée (borne le poids d'un document mensuel). */
    private const MAX_DESC_LEN = 2000;

    private const DAYS_FR = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    private const MONTHS_FR = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    /** Agenda résolu (mémorisé pour le cycle : nom + fuseau du rendu). */
    private ?string $resolvedRef  = null;
    private string  $calendarName = '';
    private string  $calendarTz   = 'UTC';

    /** Markdown généré au listing, servi par fetchTo ('YYYY-MM' => contenu). */
    private array $contents = [];

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Extrait l'adresse de l'agenda d'une saisie utilisateur : URL publique
     * (…/calendar/embed?src=…), URL iCal (…/calendar/ical/{id}/public/…),
     * lien court ?cid= (base64url de l'adresse) ou adresse brute
     * (utilisateur@gmail.com, xxx@group.calendar.google.com…). Null si non
     * reconnu. L'adresse est validée strictement puis toujours ré-encodée
     * (rawurlencode) à la construction des URLs d'API.
     */
    public static function parseFolderInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match('#[?&]src=([^&\s]+)#', $input, $m) === 1) {
            $cand = rawurldecode($m[1]);
            if (self::isCalendarId($cand)) {
                return $cand;
            }
        }
        if (preg_match('#/calendar/ical/([^/\s]+)/#', $input, $m) === 1) {
            $cand = rawurldecode($m[1]);
            if (self::isCalendarId($cand)) {
                return $cand;
            }
        }
        if (preg_match('#[?&]cid=([A-Za-z0-9_=-]+)#', $input, $m) === 1) {
            $decoded = base64_decode(strtr($m[1], '-_', '+/'), true);
            if (is_string($decoded) && self::isCalendarId($decoded)) {
                return $decoded;
            }
        }
        if (self::isCalendarId($input)) {
            return $input;
        }
        return null;
    }

    /** Adresse d'agenda plausible (les agendas fériés contiennent # : fr.french#holiday@group.v…). */
    private static function isCalendarId(string $s): bool
    {
        return mb_strlen($s) <= 320
            && preg_match('#^[A-Za-z0-9._%\#+-]+@[A-Za-z0-9.-]+$#', $s) === 1;
    }

    /**
     * Métadonnées de l'agenda (bouton « Tester » et début de cycle) : nom
     * affiché + fuseau horaire (utilisé pour le rendu des horaires).
     * Throw RuntimeException à message utilisateur si inaccessible.
     */
    public function resolveFolder(string $folderRef): array
    {
        if ($this->resolvedRef === $folderRef) {
            return ['name' => $this->calendarName];
        }
        $data = $this->requestJson('/calendars/' . rawurlencode($folderRef), []);

        $this->resolvedRef  = $folderRef;
        $this->calendarName = (string)($data['summary'] ?? $folderRef);
        $this->calendarTz   = self::safeTimezone((string)($data['timeZone'] ?? ''));
        return ['name' => $this->calendarName];
    }

    /**
     * « Listing » de l'agenda : évènements de la fenêtre glissante (occurrences
     * développées via singleEvents), regroupés par mois de début → un document
     * markdown normalisé par mois. $maxDepth est ignoré (pas de sous-dossiers).
     */
    public function listFolderRecursive(string $folderRef, int $maxDepth = 10): array
    {
        $this->resolveFolder($folderRef);
        $tz = new \DateTimeZone($this->calendarTz);

        $now   = new \DateTimeImmutable('now', $tz);
        $start = $now->modify('first day of this month')->setTime(0, 0)
            ->modify('-' . self::PAST_MONTHS . ' months');
        $end   = $now->modify('first day of next month')->setTime(0, 0)
            ->modify('+' . self::FUTURE_MONTHS . ' months');

        $byMonth   = [];
        $pageToken = null;
        do {
            $query = [
                'timeMin'      => $start->format(DATE_RFC3339),
                'timeMax'      => $end->format(DATE_RFC3339),
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'maxResults'   => '2500',
                'fields'       => 'nextPageToken,items(id,status,summary,description,location,start,end)',
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson('/calendars/' . rawurlencode($folderRef) . '/events', $query);

            foreach (($data['items'] ?? []) as $ev) {
                if (!is_array($ev) || (string)($ev['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $startAt = self::eventDate($ev['start'] ?? null, $tz);
                if ($startAt === null) {
                    continue;
                }
                $byMonth[$startAt->format('Y-m')][] = [$startAt, $ev];
            }
            $pageToken = isset($data['nextPageToken']) ? (string)$data['nextPageToken'] : null;
        } while ($pageToken !== null);

        ksort($byMonth);
        $this->contents = [];

        $files = [];
        foreach ($byMonth as $month => $list) {
            // Tri déterministe (début, titre, id) : l'API ordonne déjà par
            // startTime, mais le hash ne doit dépendre d'aucun détail de tri.
            usort($list, static fn(array $a, array $b): int => [
                $a[0]->getTimestamp(), (string)($a[1]['summary'] ?? ''), (string)($a[1]['id'] ?? ''),
            ] <=> [
                $b[0]->getTimestamp(), (string)($b[1]['summary'] ?? ''), (string)($b[1]['id'] ?? ''),
            ]);

            $md = $this->renderMonth($month, $list, $tz);
            $this->contents[$month] = $md;

            $files[] = [
                'id'       => $month,
                'name'     => 'Agenda ' . $month . ' — ' . self::MONTHS_FR[(int)substr($month, 5, 2)] . '.md',
                'mime'     => 'text/markdown',
                'size'     => strlen($md),
                'hash'     => hash('sha256', $md),
                'modified' => '',
                'export'   => null,
            ];
        }
        return $files;
    }

    /** Écrit le markdown d'un mois généré au listing (même instance = même cycle). */
    public function fetchTo(array $file, string $destPath): void
    {
        $content = $this->contents[(string)$file['id']] ?? null;
        if ($content === null) {
            throw new \RuntimeException('Contenu du mois ' . (string)$file['id'] . ' non préparé — cycle interne invalide.');
        }
        if (@file_put_contents($destPath, $content) === false) {
            throw new \RuntimeException('Écriture impossible sur le stockage local.');
        }
    }

    // ===================== Rendu markdown =====================

    /**
     * Document d'un mois : un titre par jour, une puce par évènement (horaire,
     * titre, lieu, description). Texte 100 % dérivé des évènements → hash
     * stable tant que rien ne change.
     *
     * @param list<array{0:\DateTimeImmutable,1:array}> $list
     */
    private function renderMonth(string $month, array $list, \DateTimeZone $tz): string
    {
        $monthNum  = (int)substr($month, 5, 2);
        $yearNum   = (int)substr($month, 0, 4);
        $monthName = self::MONTHS_FR[$monthNum] ?? $month;

        $out = '# Agenda « ' . $this->calendarName . ' » — ' . $monthName . ' ' . $yearNum . "\n";

        $currentDay = '';
        foreach ($list as [$startAt, $ev]) {
            $dayKey = $startAt->format('Y-m-d');
            if ($dayKey !== $currentDay) {
                $currentDay = $dayKey;
                $out .= "\n## " . self::formatDayFr($startAt) . "\n\n";
            }
            $out .= $this->renderEvent($startAt, $ev, $tz);
        }
        return $out;
    }

    /** Une puce d'évènement : `- **{horaire} — {titre}**` + lieu + description indentés. */
    private function renderEvent(\DateTimeImmutable $startAt, array $ev, \DateTimeZone $tz): string
    {
        $allDay = isset($ev['start']['date']);
        $endAt  = self::eventDate($ev['end'] ?? null, $tz);
        $title  = trim((string)($ev['summary'] ?? '')) ?: '(sans titre)';

        if ($allDay) {
            $when = 'Journée entière';
            // Fin exclusive côté Google : end.date = lendemain du dernier jour.
            $lastDay = $endAt?->modify('-1 day');
            if ($lastDay !== null && $lastDay->format('Y-m-d') > $startAt->format('Y-m-d')) {
                $when .= ', jusqu\'au ' . self::formatDayFr($lastDay);
            }
        } else {
            $when = $startAt->format('H:i');
            if ($endAt !== null) {
                $when .= ' – ' . ($endAt->format('Y-m-d') !== $startAt->format('Y-m-d')
                    ? self::formatDayFr($endAt) . ' ' . $endAt->format('H:i')
                    : $endAt->format('H:i'));
            }
        }

        $line = '- **' . $when . ' — ' . self::cleanInline($title) . "**\n";
        $loc  = self::cleanInline((string)($ev['location'] ?? ''));
        if ($loc !== '') {
            $line .= '  Lieu : ' . $loc . "\n";
        }
        $desc = self::cleanDescription((string)($ev['description'] ?? ''));
        if ($desc !== '') {
            $line .= '  ' . str_replace("\n", "\n  ", $desc) . "\n";
        }
        return $line;
    }

    /** « Vendredi 1er août 2026 » (fuseau de l'agenda, sans dépendance intl). */
    private static function formatDayFr(\DateTimeImmutable $d): string
    {
        $dayNum = (int)$d->format('j');
        return self::DAYS_FR[(int)$d->format('N')] . ' '
            . ($dayNum === 1 ? '1er' : (string)$dayNum) . ' '
            . self::MONTHS_FR[(int)$d->format('n')] . ' '
            . $d->format('Y');
    }

    /**
     * Début/fin d'évènement Google → date dans le fuseau de l'agenda.
     * `dateTime` (RFC3339 avec décalage) pour les évènements horodatés,
     * `date` (Y-m-d, minuit local) pour les journées entières.
     */
    private static function eventDate(mixed $node, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_array($node)) {
            return null;
        }
        try {
            if (isset($node['dateTime'])) {
                return (new \DateTimeImmutable((string)$node['dateTime']))->setTimezone($tz);
            }
            if (isset($node['date'])) {
                return new \DateTimeImmutable((string)$node['date'], $tz);
            }
        } catch (\Throwable) {
            // Horodatage illisible : l'évènement est ignoré par l'appelant.
        }
        return null;
    }

    /** Texte sur une ligne : balises retirées, entités décodées, blancs tassés. */
    private static function cleanInline(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('#\s+#u', ' ', $s));
    }

    /**
     * Description (souvent du HTML côté Google) → texte multi-lignes propre,
     * tronqué à MAX_DESC_LEN. L'indentation de l'appelant neutralise toute
     * interprétation markdown (une ligne « # x » ne devient pas un titre).
     */
    private static function cleanDescription(string $s): string
    {
        if (trim($s) === '') {
            return '';
        }
        $s = (string)preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(
            static fn(string $l): string => trim((string)preg_replace('#[ \t]+#u', ' ', $l)),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $s))
        );
        $s = trim((string)preg_replace("#\n{3,}#", "\n\n", implode("\n", $lines)));
        if (mb_strlen($s) > self::MAX_DESC_LEN) {
            $s = mb_substr($s, 0, self::MAX_DESC_LEN) . '…';
        }
        return $s;
    }

    /** Fuseau de l'agenda validé, UTC si absent ou inconnu de PHP. */
    private static function safeTimezone(string $tz): string
    {
        if ($tz !== '') {
            try {
                new \DateTimeZone($tz);
                return $tz;
            } catch (\Throwable) {
                // Fuseau exotique : rendu en UTC plutôt qu'échec du cycle.
            }
        }
        return 'UTC';
    }

    // ===================== Test de connexion =====================

    /**
     * Test de connexion (bouton « Tester la connexion » des Paramètres) :
     * sonde un agenda volontairement inexistant, sans agenda requis.
     * 404 = identifiants opérationnels ; tout autre statut est traduit en
     * diagnostic actionnable. Ne lève jamais.
     *
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public function testConnection(): array
    {
        [$status, $body] = $this->httpGet(
            self::API_BASE . '/calendars/' . rawurlencode(self::PROBE_CALENDAR_ID) . '?fields=id',
            15
        );
        if ($body === false) {
            return ['ok' => false, 'error' => 'Google Agenda injoignable — vérifiez l\'accès réseau sortant du serveur.'];
        }
        if ($status >= 400 && $status !== 404) {
            error_log('[KB Sync] Calendar test connexion HTTP ' . $status . ' : ' . mb_substr($body, 0, 300));
        }
        return self::connectionVerdict($status, $body);
    }

    /** Statut HTTP de la sonde → verdict à message utilisateur (pur, testable). */
    public static function connectionVerdict(int $status, string $body): array
    {
        if ($status === 404) {
            return ['ok' => true, 'detail' => 'Clé API valide et API Google Calendar activée sur le projet.'];
        }
        if ($status === 400 || $status === 401) {
            return [
                'ok'    => false,
                'error' => 'Clé API Google invalide (HTTP ' . $status . ') — vérifiez la valeur copiée '
                    . 'depuis la console Google Cloud.',
            ];
        }
        if ($status === 403) {
            if (str_contains($body, 'SERVICE_DISABLED') || str_contains($body, 'accessNotConfigured')) {
                return [
                    'ok'    => false,
                    'error' => 'Clé reconnue mais API Google Calendar non activée sur le projet — activez-la '
                        . 'dans la console Google Cloud (API et services → Bibliothèque) puis réessayez.',
                ];
            }
            if (str_contains($body, 'API_KEY_HTTP_REFERRER_BLOCKED')) {
                return [
                    'ok'    => false,
                    'error' => 'La clé refuse les appels serveur : retirez sa restriction « référents HTTP » '
                        . '(les appels viennent du serveur, sans en-tête de provenance).',
                ];
            }
            if (str_contains($body, 'API_KEY_IP_ADDRESS_BLOCKED')) {
                return [
                    'ok'    => false,
                    'error' => 'La clé restreint les adresses IP autorisées : ajoutez l\'IP du serveur '
                        . 'ou retirez la restriction.',
                ];
            }
            if (str_contains($body, 'API_KEY_SERVICE_BLOCKED')) {
                return [
                    'ok'    => false,
                    'error' => 'La clé n\'autorise pas l\'API Google Calendar : ajoutez l\'API Calendar dans '
                        . 'les restrictions d\'API de la clé.',
                ];
            }
            return [
                'ok'    => false,
                'error' => 'Accès refusé par Google (HTTP 403) — vérifiez les restrictions de la clé '
                    . 'et l\'activation de l\'API Calendar sur le projet.',
            ];
        }
        if ($status === 429) {
            return ['ok' => false, 'error' => 'Quota de l\'API Google Calendar atteint — réessayez dans quelques minutes.'];
        }
        return ['ok' => false, 'error' => 'Réponse inattendue de Google Agenda (HTTP ' . $status . ').'];
    }

    // ===================== HTTP =====================

    /** Requête JSON vers l'API Calendar (même transport que GoogleDriveProvider). */
    private function requestJson(string $path, array $query, int $timeout = 30): array
    {
        $url = self::API_BASE . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        [$status, $response] = $this->httpGet($url, $timeout);

        if ($response === false) {
            throw new \RuntimeException('Google Agenda injoignable — nouvelle tentative au prochain cycle.');
        }
        if ($status >= 400) {
            error_log('[KB Sync] Calendar GET ' . $path . ' HTTP ' . $status . ' : ' . mb_substr($response, 0, 300));
            throw $this->presentHttpError($status);
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    /** GET brut, clé en header, sans exception : [statut, corps|false]. */
    private function httpGet(string $url, int $timeout): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => 'x-goog-api-key: ' . $this->apiKey,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $ctx);
        $status   = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];
            }
        }
        return [$status, $response];
    }

    /** Statut HTTP Google → exception à message utilisateur actionnable. */
    private function presentHttpError(int $status): \RuntimeException
    {
        if ($status === 404) {
            return new \RuntimeException(
                'Agenda introuvable ou non public. Dans Google Agenda : Paramètres et partage de l\'agenda → '
                . '« Autorisations d\'accès aux événements » → cochez « Rendre disponible publiquement ».'
            );
        }
        if ($status === 403) {
            return new \RuntimeException(
                'Accès refusé par Google (HTTP 403). Vérifiez que l\'API Google Calendar est activée sur le '
                . 'projet de la clé, que la clé n\'a pas de restriction « référents HTTP », et que l\'agenda '
                . 'est rendu public.'
            );
        }
        if ($status === 400 || $status === 401) {
            return new \RuntimeException('Clé API Google invalide ou requête rejetée (HTTP ' . $status . ').');
        }
        if ($status === 429) {
            return new \RuntimeException('Quota de l\'API Google Calendar atteint — nouvelle tentative au prochain cycle.');
        }
        return new \RuntimeException('Erreur Google Agenda (HTTP ' . $status . ').');
    }
}
