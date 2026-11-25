<?php

declare(strict_types=1);

/**
 * Filtres d'une brique de workflow — SOURCE UNIQUE de leur forme, pure.
 *
 * Une étape qui consulte Gmail, Outlook ou Google Drive, ou une action qui y
 * agit, peut restreindre ce qu'elle voit et ce qu'elle touche :
 *
 *   {
 *     mail:  { from, subject, words, attachment, unread, days,     recherches d'emails
 *              exclude, to },                                  (déclencheur : sauf de, adressé à)
 *     drive: { folder, name, kind, days },                          recherches de fichiers ; dossier des créations
 *     recipients: "paul@client.fr, @client.fr"                      destinataires permis (actions email)
 *   }
 *
 * Les textes peuvent citer le dossier (`@{clé.champ}`, `@{clé}`) : ils sont
 * REMPLIS au moment de l'étape (fill), puis appliqués par SearchScope —
 * côté outils de l'agent, pas seulement dans la consigne : une recherche
 * Gmail porte la requête du filtre quoi que demande le modèle, un brouillon
 * vers une adresse non permise est refusé avant sa carte.
 */
final class WorkflowFilters
{
    public const DAYS_MAX   = 365;
    public const TEXT_MAX   = 200;
    public const FOLDER_MAX = 300;
    /** Natures de fichier Drive filtrables ('' = toutes). */
    public const DRIVE_KINDS = ['', 'doc', 'sheet', 'slides', 'pdf'];
    /** Connecteurs qui portent des filtres d'emails, de fichiers. */
    public const MAIL_CONNECTORS  = ['gmail', 'outlook'];
    public const DRIVE_CONNECTORS = ['drive'];

    /** Filtres vides. */
    public static function blank(): array
    {
        return self::normalize([]);
    }

    /** Forme canonique ; ne lève jamais. */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $mail = is_array($raw['mail'] ?? null) ? $raw['mail'] : [];
        $drive = is_array($raw['drive'] ?? null) ? $raw['drive'] : [];
        $kind = (string)($drive['kind'] ?? '');
        return [
            'mail' => [
                'from'       => self::text($mail['from'] ?? ''),
                'subject'    => self::text($mail['subject'] ?? ''),
                'words'      => self::text($mail['words'] ?? ''),
                'attachment' => self::bool($mail['attachment'] ?? false),
                'unread'     => self::bool($mail['unread'] ?? false),
                'days'       => self::days($mail['days'] ?? 0),
                // Déclencheur « email reçu » : expéditeurs écartés, destinataire
                // (l'adresse ou l'alias qui reçoit). Vides partout ailleurs.
                'exclude'    => self::text($mail['exclude'] ?? ''),
                'to'         => self::text($mail['to'] ?? ''),
            ],
            'drive' => [
                'folder' => self::text($drive['folder'] ?? '', self::FOLDER_MAX),
                'name'   => self::text($drive['name'] ?? ''),
                'kind'   => in_array($kind, self::DRIVE_KINDS, true) ? $kind : '',
                'days'   => self::days($drive['days'] ?? 0),
            ],
            'recipients' => self::text($raw['recipients'] ?? '', 300),
        ];
    }

    public static function mailIsEmpty(array $f): bool
    {
        $m = $f['mail'] ?? [];
        return trim((string)($m['from'] ?? '')) === '' && trim((string)($m['subject'] ?? '')) === '' && trim((string)($m['words'] ?? '')) === ''
            && trim((string)($m['exclude'] ?? '')) === '' && trim((string)($m['to'] ?? '')) === ''
            && empty($m['attachment']) && empty($m['unread']) && (int)($m['days'] ?? 0) === 0;
    }

    public static function driveIsEmpty(array $f): bool
    {
        $d = $f['drive'] ?? [];
        return trim((string)($d['folder'] ?? '')) === '' && trim((string)($d['name'] ?? '')) === ''
            && (string)($d['kind'] ?? '') === '' && (int)($d['days'] ?? 0) === 0;
    }

    public static function isEmpty(array $f): bool
    {
        return self::mailIsEmpty($f) && self::driveIsEmpty($f) && trim((string)($f['recipients'] ?? '')) === '';
    }

    /**
     * Les filtres qui comptent pour une brique : ceux des connecteurs qu'elle
     * consulte (étape) ou dans lequel elle agit (action). Les autres sont
     * vidés — un filtre Drive oublié sur une étape qui ne lit plus Drive ne
     * doit ni s'afficher ni s'appliquer.
     *
     * @param list<string> $connectors sources de l'étape, ou [cible] d'une action
     */
    public static function forConnectors(array $f, array $connectors, bool $acts): array
    {
        $f = self::normalize($f);
        $blank = self::blank();
        if (array_intersect($connectors, self::MAIL_CONNECTORS) === []) {
            $f['mail'] = $blank['mail'];
            $f['recipients'] = '';
        } elseif (!$acts) {
            $f['recipients'] = '';
        }
        if (array_intersect($connectors, self::DRIVE_CONNECTORS) === []) {
            $f['drive'] = $blank['drive'];
        }
        return $f;
    }

    /** Textes qui peuvent citer le dossier : chemin dans les filtres → texte. */
    public static function texts(array $f): array
    {
        return [
            'mail.from'    => (string)($f['mail']['from'] ?? ''),
            'mail.subject' => (string)($f['mail']['subject'] ?? ''),
            'mail.words'   => (string)($f['mail']['words'] ?? ''),
            'mail.exclude' => (string)($f['mail']['exclude'] ?? ''),
            'mail.to'      => (string)($f['mail']['to'] ?? ''),
            'drive.folder' => (string)($f['drive']['folder'] ?? ''),
            'drive.name'   => (string)($f['drive']['name'] ?? ''),
            'recipients'   => (string)($f['recipients'] ?? ''),
        ];
    }

    /**
     * Remplit les citations du dossier par leurs VALEURS (un champ de Fiche,
     * ou le texte court d'une pièce : l'élément en cours d'une boucle), sur
     * une ligne. Les guillemets et parenthèses sont retirés : ils fermeraient
     * la requête du fournisseur.
     */
    public static function fill(array $f, array $pieces, array $artifacts): array
    {
        $f = self::normalize($f);
        $put = static function (string $text) use ($pieces, $artifacts): string {
            if (!str_contains($text, '@{')) {
                return $text;
            }
            $v = WorkflowDeliveries::fill($text, $pieces, $artifacts, true, self::TEXT_MAX);
            $v = (string)preg_replace('/[()"«»]+/u', ' ', $v);
            return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $v)), 0, self::FOLDER_MAX);
        };
        foreach (['from', 'subject', 'words', 'exclude', 'to'] as $k) {
            $f['mail'][$k] = $put($f['mail'][$k]);
        }
        foreach (['folder', 'name'] as $k) {
            $f['drive'][$k] = $put($f['drive'][$k]);
        }
        $f['recipients'] = $put($f['recipients']);
        return $f;
    }

    /**
     * Adresses et domaines permis, séparés par des virgules ou des
     * points-virgules : « paul@client.fr, @client.fr ; acme.com ».
     *
     * @return array{addresses: list<string>, domains: list<string>, invalid: list<string>}
     */
    public static function recipientRules(string $list): array
    {
        $addresses = [];
        $domains = [];
        $invalid = [];
        foreach (preg_split('/[,;]+/u', mb_strtolower(trim($list))) ?: [] as $part) {
            $part = trim($part);
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                $part = trim($m[1]); // « Paul <paul@client.fr> »
            }
            if ($part === '' || str_contains($part, '@{')) {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $addresses[] = $part;
                continue;
            }
            $domain = ltrim($part, '@');
            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain)) {
                $domains[] = $domain;
            } else {
                $invalid[] = $part;
            }
        }
        return ['addresses' => array_values(array_unique($addresses)), 'domains' => array_values(array_unique($domains)), 'invalid' => $invalid];
    }

    /** Une adresse est-elle permise par les règles ? */
    public static function recipientAllowed(string $email, array $rules): bool
    {
        $email = mb_strtolower(trim($email));
        if (in_array($email, $rules['addresses'], true)) {
            return true;
        }
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at + 1);
        foreach ($rules['domains'] as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Résumé humain des filtres (sous-ligne du rail, volet replié). Vide sans
     * filtre.
     */
    public static function summary(array $f): string
    {
        $parts = [];
        $m = $f['mail'] ?? [];
        if (trim((string)($m['from'] ?? '')) !== '') {
            $parts[] = t('de {qui}', ['qui' => $m['from']]);
        }
        if (trim((string)($m['exclude'] ?? '')) !== '') {
            $parts[] = t('sauf de {qui}', ['qui' => $m['exclude']]);
        }
        if (trim((string)($m['to'] ?? '')) !== '') {
            $parts[] = t('adressés à {qui}', ['qui' => $m['to']]);
        }
        if (trim((string)($m['subject'] ?? '')) !== '') {
            $parts[] = t('objet « {objet} »', ['objet' => $m['subject']]);
        }
        if (trim((string)($m['words'] ?? '')) !== '') {
            $parts[] = t('contenant « {mots} »', ['mots' => $m['words']]);
        }
        if (!empty($m['attachment'])) {
            $parts[] = t('avec pièce jointe');
        }
        if (!empty($m['unread'])) {
            $parts[] = t('non lus');
        }
        if ((int)($m['days'] ?? 0) > 0) {
            $parts[] = I18n::plural((int)$m['days'], 'du dernier jour', 'des {n} derniers jours');
        }
        $d = $f['drive'] ?? [];
        if (trim((string)($d['folder'] ?? '')) !== '') {
            $parts[] = t('dossier « {dossier} »', ['dossier' => $d['folder']]);
        }
        if (trim((string)($d['name'] ?? '')) !== '') {
            $parts[] = t('nom contenant « {nom} »', ['nom' => $d['name']]);
        }
        if ((string)($d['kind'] ?? '') !== '') {
            $parts[] = self::kindLabel((string)$d['kind']);
        }
        if ((int)($d['days'] ?? 0) > 0) {
            $parts[] = I18n::plural((int)$d['days'], 'modifiés depuis un jour', 'modifiés depuis {n} jours');
        }
        if (trim((string)($f['recipients'] ?? '')) !== '') {
            $parts[] = t('vers {qui} seulement', ['qui' => $f['recipients']]);
        }
        return implode(' · ', $parts);
    }

    // ==================================================================
    // Un email correspond-il aux filtres du déclencheur ?
    // ==================================================================

    /**
     * Règle UNIQUE du déclencheur « email reçu », la même pour Gmail et
     * Outlook — appliquée par MIA sur le message lui-même, jamais confiée à
     * la recherche du fournisseur (Gmail et la recherche KQL d'Outlook ne
     * comprennent pas « objet contient » de la même façon). Pure.
     *
     * Chaque champ accepte plusieurs valeurs séparées par des virgules ou des
     * points-virgules : il suffit que L'UNE corresponde. Casse et accents sont
     * ignorés.
     *  - De / Sauf de / Adressé à : une adresse (paul@client.fr, exacte), un
     *    domaine (@client.fr ou client.fr : l'adresse en fait partie, sous-
     *    domaines compris), sinon un morceau du nom ou de l'adresse (« Dupont »,
     *    « noreply »).
     *  - Objet contient / Contient les mots : tous les mots d'une valeur
     *    figurent dans l'objet (pour « contient », dans l'objet ou le corps).
     *  - Avec une pièce jointe : au moins un fichier joint (une image collée
     *    dans le corps n'en est pas un).
     *
     * `$msg` = {de, a, cc, objet, contenu, pieces_jointes (liste) | pieces
     * (booléen)}. `$complete` faux (en-têtes seulement) : ce que le message
     * ne porte pas encore n'est pas jugé — il le sera une fois lu en entier.
     */
    public static function emailMatches(array $mail, array $msg, bool $complete = true): bool
    {
        return self::emailMismatch($mail, $msg, $complete) === null;
    }

    /**
     * Le premier critère que l'email ne remplit pas — from | exclude | to |
     * subject | words | attachment —, ou null s'il les remplit tous (règle
     * d'emailMatches ; le test des filtres de l'éditeur dit ainsi POURQUOI).
     */
    public static function emailMismatch(array $mail, array $msg, bool $complete = true): ?string
    {
        $mail = self::normalize(['mail' => $mail])['mail'];
        $has = static fn(string $k): bool => array_key_exists($k, $msg);
        if ($mail['from'] !== '' && ($complete || $has('de')) && !self::anyPartyMatches($mail['from'], [(string)($msg['de'] ?? '')])) {
            return 'from';
        }
        if ($mail['exclude'] !== '' && ($complete || $has('de')) && self::anyPartyMatches($mail['exclude'], [(string)($msg['de'] ?? '')])) {
            return 'exclude';
        }
        if ($mail['to'] !== '' && ($complete || $has('a') || $has('cc'))) {
            $parties = array_merge(self::splitParties((string)($msg['a'] ?? '')), self::splitParties((string)($msg['cc'] ?? '')));
            if (!self::anyPartyMatches($mail['to'], $parties)) {
                return 'to';
            }
        }
        if ($mail['subject'] !== '' && ($complete || $has('objet')) && !self::anyTermIn($mail['subject'], (string)($msg['objet'] ?? ''))) {
            return 'subject';
        }
        if ($mail['words'] !== '' && ($complete || $has('contenu'))
            && !self::anyTermIn($mail['words'], (string)($msg['objet'] ?? '') . "\n" . (string)($msg['contenu'] ?? ''))) {
            return 'words';
        }
        if ($mail['attachment']) {
            if (array_key_exists('pieces_jointes', $msg) && is_array($msg['pieces_jointes'])) {
                $files = array_filter($msg['pieces_jointes'], static fn($p): bool => is_array($p) ? empty($p['en_ligne']) : (string)$p !== '');
                if ($files === []) {
                    return 'attachment';
                }
            } elseif ($has('pieces')) {
                if (empty($msg['pieces'])) {
                    return 'attachment';
                }
            } elseif ($complete) {
                return 'attachment';
            }
        }
        return null;
    }

    /** Faut-il lire le message entier pour juger ces filtres (corps, pièces jointes) ? */
    public static function needsFullMessage(array $mail, array $meta): bool
    {
        $mail = self::normalize(['mail' => $mail])['mail'];
        return $mail['words'] !== '' || ($mail['attachment'] && !array_key_exists('pieces', $meta));
    }

    /** Pourquoi un email est écarté, pour l'écran. */
    public static function mismatchLabel(string $criterion, array $mail): string
    {
        $mail = self::normalize(['mail' => $mail])['mail'];
        return match ($criterion) {
            'from'       => t('expéditeur hors de « {v} »', ['v' => $mail['from']]),
            'exclude'    => t('expéditeur écarté par « {v} »', ['v' => $mail['exclude']]),
            'to'         => t('pas adressé à « {v} »', ['v' => $mail['to']]),
            'subject'    => t('objet sans « {v} »', ['v' => $mail['subject']]),
            'words'      => t('sans les mots « {v} »', ['v' => $mail['words']]),
            'attachment' => t('sans pièce jointe'),
            default      => t('écarté'),
        };
    }

    /** Valeurs d'un champ de filtre : séparées par des virgules ou des points-virgules. @return list<string> */
    public static function terms(string $value): array
    {
        $out = [];
        foreach (preg_split('/[,;]+/u', $value) ?: [] as $t) {
            $t = trim($t);
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** « Paul <paul@x.fr>, Marie <m@y.fr> » → une entrée par personne. @return list<string> */
    private static function splitParties(string $list): array
    {
        $out = [];
        // Une virgule DANS un nom entre guillemets (« "Dupont, Paul" <p@x.fr> ») ne coupe pas.
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/u', $list) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** L'une des valeurs désigne-t-elle l'une des personnes (« Nom <adresse> ») ? */
    private static function anyPartyMatches(string $value, array $parties): bool
    {
        foreach (self::terms($value) as $term) {
            foreach ($parties as $party) {
                if (self::partyMatches($term, (string)$party)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function partyMatches(string $term, string $party): bool
    {
        $term = WorkflowLoop::fold($term);
        if (preg_match('/<([^>]+)>/', $term, $m)) {
            $term = trim($m[1]); // « Paul <paul@client.fr> » collé tel quel
        }
        $party = WorkflowLoop::fold($party);
        $address = preg_match('/<([^>]+)>/', $party, $m) ? trim($m[1]) : (str_contains($party, '@') && !str_contains($party, ' ') ? $party : '');
        if ($term === '' || $party === '') {
            return false;
        }
        if (filter_var($term, FILTER_VALIDATE_EMAIL)) {
            return $address === $term;
        }
        $domain = ltrim($term, '@');
        if ((str_starts_with($term, '@') || !str_contains($term, ' ')) && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain)) {
            $at = strrpos($address, '@');
            $host = $at === false ? '' : substr($address, $at + 1);
            return $host !== '' && ($host === $domain || str_ends_with($host, '.' . $domain));
        }
        return str_contains($party, $term);
    }

    /** Tous les mots de l'une des valeurs figurent-ils dans le texte ? */
    private static function anyTermIn(string $value, string $text): bool
    {
        $hay = WorkflowLoop::fold($text);
        foreach (self::terms($value) as $term) {
            $words = preg_split('/\s+/u', WorkflowLoop::fold($term)) ?: [];
            $words = array_values(array_filter($words, static fn($w) => $w !== ''));
            if ($words !== [] && array_reduce($words, static fn(bool $ok, string $w): bool => $ok && str_contains($hay, $w), true)) {
                return true;
            }
        }
        return false;
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'doc'    => t('Documents'),
            'sheet'  => t('Feuilles de calcul'),
            'slides' => t('Présentations'),
            'pdf'    => t('PDF'),
            default  => t('Tous les fichiers'),
        };
    }

    private static function text(mixed $v, int $max = self::TEXT_MAX): string
    {
        if (!is_scalar($v)) {
            return '';
        }
        $s = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v);
        return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $s)), 0, $max);
    }

    private static function bool(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private static function days(mixed $v): int
    {
        return max(0, min(self::DAYS_MAX, (int)(is_scalar($v) ? $v : 0)));
    }
}
