<?php

declare(strict_types=1);

/**
 * Filtres d'une brique de workflow — SOURCE UNIQUE de leur forme, pure.
 *
 * Une étape qui consulte Gmail, Outlook ou Google Drive, ou une action qui y
 * agit, peut restreindre ce qu'elle voit et ce qu'elle touche :
 *
 *   {
 *     mail:  { from, subject, words, attachment, unread, days },   recherches d'emails
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
        foreach (['from', 'subject', 'words'] as $k) {
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
        if (trim((string)($m['subject'] ?? '')) !== '') {
            $parts[] = t('objet « {objet} »', ['objet' => $m['subject']]);
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
