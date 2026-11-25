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
 * Une brique Slack qui PUBLIE y ajoute sa destination et ses fichiers
 * (WorkflowSlack::forPass) : posés sur le message quoi que le modèle écrive.
 *
 * Pur ; un périmètre vide ne change rien (le chat ordinaire en a un vide).
 */
final class SearchScope
{
    private function __construct(private readonly array $f, private readonly ?array $slack = null)
    {
    }

    public static function none(): self
    {
        return new self(WorkflowFilters::blank());
    }

    /**
     * @param array $filters filtres WorkflowFilters, remplis
     * @param ?array $slack réglages d'une brique Slack (charge du job) — gardés s'ils publient
     */
    public static function fromFilters(array $filters, ?array $slack = null): self
    {
        return new self(WorkflowFilters::normalize($filters), WorkflowSlack::posts($slack) ? $slack : null);
    }

    // ------------------------------------------------------------------
    // Slack (brique qui publie)
    // ------------------------------------------------------------------

    /** Publication Slack imposée par une brique de workflow (destination, fichiers), null sinon. */
    public function slackPost(): ?array
    {
        return $this->slack;
    }

    /**
     * Arguments d'un message Slack : la destination de la brique remplace
     * celle du modèle (canal OU personne), jamais de fil. Sans brique Slack :
     * inchangés.
     */
    public function applyToSlackPost(array $args): array
    {
        $s = $this->slack;
        if ($s === null) {
            return $args;
        }
        unset($args['canal'], $args['personne'], $args['repondre_a']);
        if (($s['where'] ?? '') === WorkflowSpec::SLACK_PERSON) {
            $args['personne'] = (string)($s['person'] ?? '');
        } else {
            $args['canal'] = (string)($s['channel'] ?? '');
        }
        return $args;
    }

    /** Fichiers que la brique joint au message (références internes, posées par le serveur). @return list<array> */
    public function slackAttach(): array
    {
        return $this->slack !== null ? array_values(array_filter((array)($this->slack['attach'] ?? []), 'is_array')) : [];
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
        // Une réunion avec invités leur ÉCRIT (Outlook envoie l'invitation ;
        // Google l'envoie par défaut et l'ajoute à leur agenda) : les invités
        // suivent le même filtre qu'un destinataire.
        $invite = in_array($tool, [
            UserOutlookCalendarService::TOOL_CREATE, UserOutlookCalendarService::TOOL_UPDATE,
            UserCalendarService::TOOL_CREATE, UserCalendarService::TOOL_UPDATE,
        ], true);
        if ((!$invite && !in_array($tool, ['creer_brouillon_gmail', 'creer_brouillon_outlook'], true)) || trim($this->f['recipients']) === '') {
            return null;
        }
        $rules = WorkflowFilters::recipientRules($this->f['recipients']);
        if ($rules['addresses'] === [] && $rules['domains'] === []) {
            return null;
        }
        if ($invite) {
            $parts = [];
            // La salle aussi : sa boîte reçoit la demande de réservation.
            foreach (['invites', 'invites_optionnels', 'participants', 'ajouter_invites', 'ajouter_invites_optionnels', 'salle'] as $k) {
                $v = $args[$k] ?? null;
                $parts[] = is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : (is_scalar($v) ? (string)$v : '');
            }
            $to = implode(', ', $parts);
        } else {
            $to = trim((string)($args['destinataire'] ?? '') . ', ' . (string)($args['cc'] ?? ''), ', ');
        }
        $emails = [];
        preg_match_all('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}/', $to, $m);
        foreach ($m[0] ?? [] as $e) {
            $emails[] = mb_strtolower($e);
        }
        if ($emails === []) {
            if ($invite) {
                return null; // évènement sans invité : personne à qui écrire
            }
            return "Le workflow n'autorise d'emails que vers : " . $this->f['recipients']
                . ". Indique explicitement le destinataire (« destinataire »), même pour une réponse.";
        }
        $refused = array_values(array_filter(array_unique($emails), static fn(string $e): bool => !WorkflowFilters::recipientAllowed($e, $rules)));
        if ($refused !== []) {
            return ($invite ? 'Invité' : 'Destinataire') . " non autorisé par le filtre du workflow : " . implode(', ', $refused)
                . ". Seules ces adresses sont permises : " . $this->f['recipients'] . ".";
        }
        return null;
    }

    /**
     * Une création Drive part dans le dossier du périmètre (rangée après
     * création) ; un message Slack, vers la destination de la brique, avec
     * ses fichiers.
     */
    public function applyToWrite(string $tool, array $args): array
    {
        if (in_array($tool, ['creer_google_doc', 'creer_google_sheet'], true) && $this->driveFolder() !== '') {
            $args[WorkflowDeliveries::FOLDER_KEY] = $this->driveFolder();
        }
        if ($tool === 'envoyer_message_slack' && $this->slack !== null) {
            $args = $this->applyToSlackPost($args);
            if ($this->slackAttach() !== []) {
                $args[WorkflowDeliveries::ATTACH_KEY] = $this->slackAttach();
            }
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

    // ------------------------------------------------------------------
    // Collectes dans une table de travail (WorkTableTools)
    // ------------------------------------------------------------------

    /**
     * Requête d'une collecte d'emails : les critères du filtre s'ajoutent à
     * celle du modèle, comme pour une recherche.
     */
    public function collectQuery(string $source, string $query): string
    {
        return match ($source) {
            WorkTableCollector::SOURCE_GMAIL   => $this->gmailQuery($query),
            WorkTableCollector::SOURCE_OUTLOOK => $this->outlookQuery($query),
            default                            => $query,
        };
    }

    /** Dossier d'une collecte de fichiers : celui du filtre, quand le modèle n'en nomme aucun. */
    public function collectFolder(string $folder): string
    {
        return trim($folder) !== '' ? $folder : $this->driveFolder();
    }

    /**
     * Une collecte que le périmètre ne permet pas de tenir, AVANT tout appel :
     * Outlook avec « non lus », « pièce jointe » ou une période (ils ne se
     * vérifient que sur des résultats de recherche) ; un dossier Drive hors
     * de celui du filtre. Rend le message pour le modèle, ou null.
     */
    public function collectRefusal(string $source, string $folder): ?string
    {
        $m = $this->f['mail'];
        if ($source === WorkTableCollector::SOURCE_OUTLOOK && ($m['unread'] || $m['attachment'] || $m['days'] > 0)) {
            return "Les filtres de cette étape (non lus, pièce jointe ou période) ne s'appliquent pas à une collecte Outlook dans une "
                . "table : utilise rechercher_outlook, qui les applique.";
        }
        $root = $this->driveFolder();
        if ($source === WorkTableCollector::SOURCE_DRIVE && $root !== '' && trim($folder) !== '') {
            $f = self::fold($folder);
            $r = self::fold($root);
            // Minuscules sans accents ni ponctuation : « Clients/Durand/2026 » → « clients durand 2026 ».
            if ($f !== $r && !str_starts_with($f, $r . ' ') && !str_starts_with($f, $r . '/')) {
                return "Cette étape ne consulte que le dossier « " . $root . " » (et ses sous-dossiers) : collecte dans ce dossier.";
            }
        }
        return null;
    }

    /** Lignes d'une collecte de fichiers gardées : nature, nom, période (WorkTableCollector::fileRow). */
    public function filterFileRows(array $rows, ?\DateTimeImmutable $now = null): array
    {
        $d = $this->f['drive'];
        if ($d['kind'] === '' && $d['name'] === '' && $d['days'] === 0) {
            return $rows;
        }
        $limit = $d['days'] > 0 ? ($now ?? new \DateTimeImmutable())->modify('-' . $d['days'] . ' days')->format('Y-m-d') : null;
        $name = self::fold($d['name']);
        return array_values(array_filter($rows, static function ($r) use ($d, $limit, $name): bool {
            if (!is_array($r)) {
                return false;
            }
            if ($d['kind'] !== '' && !self::kindMatches($d['kind'], (string)($r['type'] ?? ''))) {
                return false;
            }
            if ($name !== '' && !str_contains(self::fold((string)($r['nom'] ?? '')), $name)) {
                return false;
            }
            if ($limit !== null) {
                $key = WorkTableOps::dateKey((string)($r['modifie_le'] ?? ''));
                if ($key !== null && substr($key, 0, 10) < $limit) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** Minuscules sans accents : la même règle que la recherche Drive. */
    private static function fold(string $s): string
    {
        return UserDriveService::normalizeForSearch(trim($s));
    }
}
