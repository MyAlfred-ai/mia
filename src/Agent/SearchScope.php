<?php

declare(strict_types=1);

/**
 * Périmètre IMPOSÉ aux outils de l'agent pour un tour : ce que ses recherches
 * d'emails et de fichiers voient, où ses fichiers sont créés, à qui ses
 * emails peuvent partir. Construit depuis les filtres d'une brique de
 * workflow (WorkflowFilters, déjà remplis), appliqué par les fournisseurs
 * d'outils Gmail, Outlook, Drive et par les recherches par intention — pas
 * seulement annoncé au modèle : la requête du filtre accompagne CHAQUE
 * recherche, quoi que le modèle demande.
 *
 * Pur ; un périmètre vide ne change rien (le chat ordinaire en a un vide).
 */
final class SearchScope
{
    private function __construct(private readonly array $f)
    {
    }

    public static function none(): self
    {
        return new self(WorkflowFilters::blank());
    }

    /** @param array $filters filtres WorkflowFilters, remplis */
    public static function fromFilters(array $filters): self
    {
        return new self(WorkflowFilters::normalize($filters));
    }

    public function isEmpty(): bool
    {
        return WorkflowFilters::isEmpty($this->f);
    }

    public function filters(): array
    {
        return $this->f;
    }

    // ------------------------------------------------------------------
    // Emails
    // ------------------------------------------------------------------

    /** Requête Gmail : celle du modèle, ET les critères du filtre (syntaxe native). */
    public function gmailQuery(string $query): string
    {
        $m = $this->f['mail'];
        $parts = [trim($query)];
        if ($m['from'] !== '') {
            $parts[] = 'from:(' . self::plain($m['from']) . ')';
        }
        if ($m['subject'] !== '') {
            $parts[] = 'subject:(' . self::plain($m['subject']) . ')';
        }
        if ($m['words'] !== '') {
            $parts[] = self::plain($m['words']);
        }
        if ($m['attachment']) {
            $parts[] = 'has:attachment';
        }
        if ($m['unread']) {
            $parts[] = 'is:unread';
        }
        if ($m['days'] > 0) {
            $parts[] = 'newer_than:' . $m['days'] . 'd';
        }
        return trim(implode(' ', array_filter($parts, static fn(string $p): bool => $p !== '')));
    }

    /**
     * Requête Outlook (`$search` KQL) : celle du modèle, ET l'expéditeur,
     * l'objet et les mots du filtre. Lu, pièce jointe et période se vérifient
     * sur les résultats (filterOutlook) : leur syntaxe KQL n'est pas garantie
     * d'une boîte à l'autre.
     */
    public function outlookQuery(string $query): string
    {
        $m = $this->f['mail'];
        $parts = [trim($query)];
        if ($m['from'] !== '') {
            $parts[] = 'from:' . self::plain($m['from']);
        }
        if ($m['subject'] !== '') {
            $parts[] = 'subject:' . self::plain($m['subject']);
        }
        if ($m['words'] !== '') {
            $parts[] = self::plain($m['words']);
        }
        return trim(implode(' ', array_filter($parts, static fn(string $p): bool => $p !== '')));
    }

    /** Résultats Outlook gardés : non lus, avec pièce jointe, période. */
    public function filterOutlook(array $results, ?\DateTimeImmutable $now = null): array
    {
        $m = $this->f['mail'];
        if (!$m['unread'] && !$m['attachment'] && $m['days'] === 0) {
            return $results;
        }
        $limit = $m['days'] > 0 ? ($now ?? new \DateTimeImmutable())->modify('-' . $m['days'] . ' days') : null;
        return array_values(array_filter($results, static function ($r) use ($m, $limit): bool {
            if (!is_array($r)) {
                return false;
            }
            if ($m['unread'] && !empty($r['lu'])) {
                return false;
            }
            if ($m['attachment'] && empty($r['pieces_jointes'])) {
                return false;
            }
            if ($limit !== null) {
                $d = \DateTimeImmutable::createFromFormat('d/m/Y H:i', (string)($r['date'] ?? ''));
                if ($d !== false && $d < $limit) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** Le périmètre porte-t-il un critère d'email ? */
    public function hasMailFilter(): bool
    {
        return !WorkflowFilters::mailIsEmpty($this->f);
    }

    // ------------------------------------------------------------------
    // Google Drive
    // ------------------------------------------------------------------

    /** Dossier imposé aux recherches et aux créations ('' = aucun). */
    public function driveFolder(): string
    {
        return $this->f['drive']['folder'];
    }

    /**
     * Requête Drive : celle du modèle, sinon le nom du filtre — une
     * recherche Drive ne part jamais vide.
     */
    public function driveQuery(string $query): string
    {
        $query = trim($query);
        return $query !== '' ? $query : $this->f['drive']['name'];
    }

    /** Résultats Drive gardés : nature, nom, période (les dossiers passent : on les parcourt). */
    public function filterDrive(array $results, ?\DateTimeImmutable $now = null): array
    {
        $d = $this->f['drive'];
        if ($d['kind'] === '' && $d['name'] === '' && $d['days'] === 0) {
            return $results;
        }
        $limit = $d['days'] > 0 ? ($now ?? new \DateTimeImmutable())->modify('-' . $d['days'] . ' days') : null;
        $name = self::fold($d['name']);
        return array_values(array_filter($results, static function ($r) use ($d, $limit, $name): bool {
            if (!is_array($r)) {
                return false;
            }
            if (!empty($r['dossier'])) {
                return true;
            }
            if ($d['kind'] !== '' && !self::kindMatches($d['kind'], (string)($r['type'] ?? ''))) {
                return false;
            }
            if ($name !== '' && !str_contains(self::fold((string)($r['nom'] ?? '')), $name)) {
                return false;
            }
            if ($limit !== null && trim((string)($r['modifie'] ?? '')) !== '') {
                try {
                    if (new \DateTimeImmutable((string)$r['modifie']) < $limit) {
                        return false;
                    }
                } catch (\Throwable) {
                }
            }
            return true;
        }));
    }

    /** La nature d'un fichier Drive (libellé humain du service) correspond-elle ? */
    public static function kindMatches(string $kind, string $type): bool
    {
        return match ($kind) {
            'doc'    => in_array($type, ['Google Docs', 'Word (docx)'], true),
            'sheet'  => in_array($type, ['Google Sheets', 'Excel (xlsx)'], true),
            'slides' => in_array($type, ['Google Slides', 'PowerPoint (pptx)'], true),
            'pdf'    => $type === 'PDF',
            default  => true,
        };
    }

    /** Le périmètre porte-t-il un critère de fichier Drive ? */
    public function hasDriveFilter(): bool
    {
        return !WorkflowFilters::driveIsEmpty($this->f);
    }

    // ------------------------------------------------------------------
    // Écritures
    // ------------------------------------------------------------------

    /**
     * Une écriture que le périmètre refuse, AVANT sa carte : un email vers
     * une adresse non permise. Rend le message pour le modèle, ou null.
     */
    public function writeRefusal(string $tool, array $args): ?string
    {
        if (!in_array($tool, ['creer_brouillon_gmail', 'creer_brouillon_outlook'], true) || trim($this->f['recipients']) === '') {
            return null;
        }
        $rules = WorkflowFilters::recipientRules($this->f['recipients']);
        if ($rules['addresses'] === [] && $rules['domains'] === []) {
            return null;
        }
        $to = trim((string)($args['destinataire'] ?? '') . ', ' . (string)($args['cc'] ?? ''), ', ');
        $emails = [];
        preg_match_all('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}/', $to, $m);
        foreach ($m[0] ?? [] as $e) {
            $emails[] = mb_strtolower($e);
        }
        if ($emails === []) {
            return "Le workflow n'autorise d'emails que vers : " . $this->f['recipients']
                . ". Indique explicitement le destinataire (« destinataire »), même pour une réponse.";
        }
        $refused = array_values(array_filter(array_unique($emails), static fn(string $e): bool => !WorkflowFilters::recipientAllowed($e, $rules)));
        if ($refused !== []) {
            return "Destinataire non autorisé par le filtre du workflow : " . implode(', ', $refused)
                . ". Seules ces adresses sont permises : " . $this->f['recipients'] . ".";
        }
        return null;
    }

    /** Une création Drive part dans le dossier du périmètre (rangée après création). */
    public function applyToWrite(string $tool, array $args): array
    {
        if (in_array($tool, ['creer_google_doc', 'creer_google_sheet'], true) && $this->driveFolder() !== '') {
            $args[WorkflowDeliveries::FOLDER_KEY] = $this->driveFolder();
        }
        return $args;
    }

    // ------------------------------------------------------------------
    // Consigne
    // ------------------------------------------------------------------

    /**
     * Ce que le modèle doit savoir du périmètre (méthode, en français : ne
     * cite rien à l'écran). Vide sans filtre.
     */
    public function promptNote(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $lines = [];
        if ($this->hasMailFilter()) {
            $lines[] = '- Recherches d’emails : le filtre « ' . $this->gmailQuery('') . ' » est ajouté à chacune de tes recherches (Gmail comme Outlook) ; ne cherche pas à le contourner, cherche dans ce périmètre.';
        }
        if ($this->hasDriveFilter()) {
            $d = $this->f['drive'];
            $bits = [];
            if ($d['folder'] !== '') {
                $bits[] = 'dans le dossier « ' . $d['folder'] . ' » (tes recherches y sont limitées et tes créations y sont rangées)';
            }
            if ($d['name'] !== '') {
                $bits[] = 'dont le nom contient « ' . $d['name'] . ' »';
            }
            if ($d['kind'] !== '') {
                $bits[] = 'de nature « ' . WorkflowFilters::kindLabel($d['kind']) . ' »';
            }
            if ($d['days'] > 0) {
                $bits[] = 'modifiés depuis ' . $d['days'] . ' jours au plus';
            }
            $lines[] = '- Fichiers Google Drive : seulement ceux ' . implode(', ', $bits) . '.';
        }
        if (trim($this->f['recipients']) !== '') {
            $lines[] = '- Emails préparés : seulement vers ' . $this->f['recipients'] . ' — tout autre destinataire est refusé.';
        }
        return "\n\nPÉRIMÈTRE IMPOSÉ PAR LE WORKFLOW :\n" . implode("\n", $lines);
    }

    // ------------------------------------------------------------------

    private static function plain(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[()"]+/u', ' ', $s)));
    }

    /** Minuscules sans accents : la même règle que la recherche Drive. */
    private static function fold(string $s): string
    {
        return UserDriveService::normalizeForSearch(trim($s));
    }
}
