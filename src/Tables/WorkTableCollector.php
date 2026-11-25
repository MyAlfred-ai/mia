<?php

declare(strict_types=1);

/**
 * Collecte EXHAUSTIVE d'une source vers une table de travail.
 *
 * Chaque méthode rend la même forme — `{columns, rows, notes, truncated,
 * stopped, label}` — que l'outil `collecter_dans_table` range en base. Les
 * services font le travail (pages suivies jusqu'au bout, en-têtes lus en
 * parallèle) ; ici, on ne fait que donner aux éléments une forme de LIGNE,
 * la même pour Gmail et Outlook : deux boîtes collectées dans une même table
 * se croisent et se trient ensemble.
 *
 * Rien n'est rendu au modèle ici : il ne verra que le nombre de lignes, les
 * colonnes et quelques exemples.
 */
final class WorkTableCollector
{
    public const SOURCE_GMAIL      = 'emails_gmail';
    public const SOURCE_OUTLOOK    = 'emails_outlook';
    public const SOURCE_DRIVE      = 'fichiers_drive';
    public const SOURCE_DRIVE_ROWS = 'lignes_fichier_drive';
    public const SOURCE_ATTACHMENT = 'lignes_piece_jointe';

    /** Source → connecteur qui la porte (null : la conversation elle-même). */
    public const SOURCES = [
        self::SOURCE_GMAIL      => 'gmail',
        self::SOURCE_OUTLOOK    => 'outlook',
        self::SOURCE_DRIVE      => 'drive',
        self::SOURCE_DRIVE_ROWS => 'drive',
        self::SOURCE_ATTACHMENT => null,
    ];

    /**
     * Au-delà de ce nombre de lignes, un classeur joint ne se compte plus de
     * tête : les outils de tables sont exposés d'office (sized(), bigSheets()).
     */
    public const BIG_SHEET_LINES = 100;

    public const EMAILS_DEFAULT = 200;
    public const FILES_DEFAULT  = 500;

    /** Ce que la source rapporte, en mots (résultat de l'outil, trace). */
    public static function label(string $source): string
    {
        return match ($source) {
            self::SOURCE_GMAIL      => 'Emails Gmail',
            self::SOURCE_OUTLOOK    => 'Emails Outlook',
            self::SOURCE_DRIVE      => 'Fichiers Google Drive',
            self::SOURCE_DRIVE_ROWS => 'Lignes d\'un classeur Google Drive',
            self::SOURCE_ATTACHMENT => 'Lignes d\'un classeur joint',
            default                 => $source,
        };
    }

    // ==================================================================
    // Emails (Gmail, Outlook)
    // ==================================================================

    /** Colonnes d'une table d'emails — les mêmes pour les deux boîtes. */
    public static function emailColumns(): array
    {
        return WorkTableOps::columns([
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'expediteur', 'label' => 'Expéditeur'],
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'destinataires', 'label' => 'Destinataires'],
            ['key' => 'objet', 'label' => 'Objet'],
            ['key' => 'extrait', 'label' => 'Extrait'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'id', 'label' => 'Identifiant', 'tech' => true],
        ]);
    }

    /**
     * @param object $service UserGmailService | UserOutlookService (collectMessages)
     * @return array{columns: list<array>, rows: list<array<string,string>>, notes: list<string>, truncated: bool, stopped: bool}
     */
    public static function emails(object $service, string $source, string $query, int $limit, ?\DateTimeZone $tz, ?\Closure $progress = null): array
    {
        $res   = $service->collectMessages($query, $limit, $progress);
        $label = $source === self::SOURCE_OUTLOOK ? 'Outlook' : 'Gmail';
        $rows  = [];
        foreach ((array)$res['messages'] as $m) {
            $rows[] = self::emailRow((array)$m, $label, $tz);
        }
        $notes = [];
        if (!empty($res['tronque'])) {
            $notes[] = 'La recherche compte plus d\'emails que la limite demandée : seuls les ' . count($rows)
                . ' plus récents sont dans la table. Augmente `max` ou précise la requête (période, expéditeur) pour tout avoir.';
        }
        if ((int)($res['echecs'] ?? 0) > 0) {
            $notes[] = (int)$res['echecs'] . ' email(s) n\'ont pas pu être lus (limite de débit) : ils manquent à la table.';
        }
        return ['columns' => self::emailColumns(), 'rows' => $rows, 'notes' => $notes,
            'truncated' => !empty($res['tronque']), 'stopped' => !empty($res['interrompu'])];
    }

    /** Un email collecté → une ligne. Pure. */
    public static function emailRow(array $m, string $sourceLabel, ?\DateTimeZone $tz = null): array
    {
        $from    = (string)($m['de'] ?? '');
        $address = WorkTableOps::emails($from)[0] ?? '';
        $name    = trim((string)preg_replace('/<[^>]*>/', '', $from), " \t\"'");
        return [
            'date'          => WorkTableOps::stamp((int)($m['recu'] ?? 0), $tz),
            'expediteur'    => WorkTableOps::cell($name !== '' ? $name : $address),
            'email'         => $address,
            'destinataires' => WorkTableOps::cell(implode(', ', WorkTableOps::emails((string)($m['a'] ?? ''))) ?: (string)($m['a'] ?? '')),
            'objet'         => WorkTableOps::cell((string)($m['objet'] ?? '')),
            'extrait'       => WorkTableOps::cell((string)($m['extrait'] ?? '')),
            'source'        => $sourceLabel,
            'id'            => (string)($m['id'] ?? ''),
        ];
    }

    // ==================================================================
    // Fichiers d'un dossier Drive
    // ==================================================================

    public static function fileColumns(): array
    {
        return WorkTableOps::columns([
            ['key' => 'nom', 'label' => 'Nom'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'dossier', 'label' => 'Dossier'],
            ['key' => 'modifie_le', 'label' => 'Modifié le', 'type' => 'date'],
            ['key' => 'taille_ko', 'label' => 'Taille (Ko)', 'type' => 'number'],
            ['key' => 'lien', 'label' => 'Lien'],
            ['key' => 'id', 'label' => 'Identifiant', 'tech' => true],
        ]);
    }

    /**
     * @param object $drive UserDriveService (collectFiles)
     * @return array{columns: list<array>, rows: list<array<string,string>>, notes: list<string>, truncated: bool, stopped: bool, folder: string}
     */
    public static function driveFiles(object $drive, string $folder, bool $recursive, string $nameFilter, int $limit, ?\DateTimeZone $tz, ?\Closure $progress = null): array
    {
        if (trim($folder) === '') {
            throw new \RuntimeException('Indique le dossier Google Drive à parcourir : son lien, son nom ou son chemin.');
        }
        $res    = $drive->collectFiles($folder, $recursive, $limit, $progress);
        $filter = WorkTableOps::fold($nameFilter);
        $rows   = [];
        foreach ((array)$res['fichiers'] as $f) {
            if ($filter !== '' && !str_contains(WorkTableOps::fold((string)$f['nom']), $filter)) {
                continue;
            }
            $rows[] = self::fileRow((array)$f, (string)$res['dossier']['nom'], $tz);
        }
        $notes = [];
        if (!empty($res['tronque'])) {
            $notes[] = 'Le dossier compte plus de fichiers (ou de sous-dossiers) que la limite : la table est incomplète. '
                . 'Augmente `max`, ou collecte un sous-dossier à la fois.';
        }
        return ['columns' => self::fileColumns(), 'rows' => $rows, 'notes' => $notes,
            'truncated' => !empty($res['tronque']), 'stopped' => !empty($res['interrompu']), 'folder' => (string)$res['dossier']['nom']];
    }

    /** Un fichier Drive → une ligne. Pure. */
    public static function fileRow(array $f, string $rootName, ?\DateTimeZone $tz = null): array
    {
        $ts   = strtotime((string)($f['modifie'] ?? ''));
        $size = $f['taille'] ?? null;
        $path = trim((string)($f['chemin'] ?? ''), '/');
        return [
            'nom'        => WorkTableOps::cell((string)($f['nom'] ?? '')),
            'type'       => (string)($f['type'] ?? ''),
            'dossier'    => WorkTableOps::cell($path !== '' ? $rootName . '/' . $path : $rootName),
            'modifie_le' => $ts === false ? '' : WorkTableOps::stamp($ts, $tz),
            'taille_ko'  => $size === null ? '' : (string)max(1, (int)round(((int)$size) / 1024)),
            'lien'       => (string)($f['lien'] ?? ''),
            'id'         => (string)($f['id'] ?? ''),
        ];
    }

    // ==================================================================
    // Lignes d'un classeur (Drive, pièce jointe de la conversation)
    // ==================================================================

    /**
     * Lignes d'un classeur Google Drive (Excel, CSV, Google Sheets), lu dans
     * un fichier temporaire supprimé aussitôt.
     *
     * @return array{columns: list<array>, rows: list<array<string,string>>, notes: list<string>, truncated: bool, stopped: bool, file: string}
     */
    public static function driveSheet(object $drive, string $ref, string $sheet): array
    {
        if (trim($ref) === '') {
            throw new \RuntimeException('Indique le classeur Google Drive à lire : son lien, son nom ou son chemin.');
        }
        $file = $drive->downloadFile($ref);
        $name = (string)$file['nom'];
        if (!LoopFiles::isSheet((string)$file['mime'], $name)) {
            throw new \RuntimeException('« ' . $name . ' » n\'est pas un classeur : désigne un fichier Excel, CSV ou Google Sheets.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mia_table_');
        if ($tmp === false) {
            throw new \RuntimeException('Écriture temporaire impossible sur le serveur.');
        }
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = $tmp . ($ext !== '' ? '.' . $ext : '');
        try {
            file_put_contents($path, (string)$file['bytes']);
            return self::fromGrid(LoopFiles::sheet($path, (string)$file['mime'], $name, $sheet), $name);
        } finally {
            @unlink($path);
            @unlink($tmp);
        }
    }

    /**
     * Classeurs joints dans la conversation (le plus récent de chaque nom),
     * numérotés pour être désignés.
     *
     * @param list<array> $history lignes de `messages` (files_json)
     * @return list<array{numero:int,nom:string,type:string,path:string,extracted:string,message_id:int}>
     */
    public static function sheetAttachments(array $history): array
    {
        $byName = [];
        foreach ($history as $row) {
            // Les pièces jointes d'un message peuvent peser lourd (images et
            // PDF en base64) : on ne les décode que si un classeur s'y annonce.
            $hint = (string)($row['files_json'] ?? '') . (string)($row['file_name'] ?? '') . (string)($row['file_type'] ?? '');
            if ($hint === '' || preg_match('/spreadsheet|ms-excel|csv|tab-separated|\.xlsx?\b|\.xlsm\b|\.ods\b|\.tsv\b/i', $hint) !== 1) {
                continue;
            }
            foreach (ContextWindow::files((array)$row) as $f) {
                if (!ContextWindow::isSentFile($f) || !self::isSheetAttachment($f['type'], $f['name'])) {
                    continue;
                }
                $key = mb_strtolower($f['name']);
                unset($byName[$key]);
                $byName[$key] = $f + ['message_id' => (int)($row['id'] ?? 0)];
            }
        }
        $out = [];
        foreach (array_values($byName) as $i => $f) {
            $out[] = ['numero' => $i + 1, 'nom' => $f['name'], 'type' => $f['type'], 'path' => $f['path'], 'extracted' => $f['extracted'],
                'message_id' => (int)$f['message_id']];
        }
        return $out;
    }

    /**
     * Les mêmes classeurs, avec leur taille : `lignes` (lignes non vides du
     * texte gardé par le message) et `gros` — vrai quand le classeur ne peut
     * pas être lu d'un coup d'œil : plus de BIG_SHEET_LINES lignes, texte
     * coupé faute de place dans le contexte ($cutNames, ContextWindow::
     * boundFiles), ou texte absent (seul le fichier fait foi). Pure.
     *
     * Un gros classeur expose les outils de tables SANS mot-clé dans la
     * demande : sans eux, le modèle répondrait depuis un texte coupé, ou
     * compterait de tête plusieurs centaines de lignes.
     *
     * @param list<array>  $attachments rendu par sheetAttachments()
     * @param list<string> $cutNames    noms des fichiers coupés pour ce tour
     * @return list<array> mêmes entrées + {lignes:int, gros:bool}
     */
    public static function sized(array $attachments, array $cutNames = []): array
    {
        $cut = array_map(static fn($n): string => mb_strtolower((string)$n), $cutNames);
        foreach ($attachments as &$f) {
            $text  = trim((string)($f['extracted'] ?? ''));
            $lines = $text === '' ? 0 : count(array_filter(explode("\n", $text), static fn(string $l): bool => trim($l) !== ''));
            $f['lignes'] = $lines;
            $f['gros']   = $lines > self::BIG_SHEET_LINES
                || $text === ''
                || in_array(mb_strtolower((string)($f['nom'] ?? '')), $cut, true);
        }
        unset($f);
        return $attachments;
    }

    /**
     * Les gros classeurs parmi ceux de sized().
     *
     * @return list<array>
     */
    public static function bigSheets(array $sized): array
    {
        return array_values(array_filter($sized, static fn(array $f): bool => !empty($f['gros'])));
    }

    private static function isSheetAttachment(string $mime, string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['xlsx', 'xlsm', 'xls', 'ods', 'csv', 'tsv'], true)
            || str_contains($mime, 'spreadsheet') || str_contains($mime, 'ms-excel')
            || in_array($mime, ['text/csv', 'text/tab-separated-values'], true);
    }

    /**
     * Lignes d'un classeur joint, désigné par son numéro ou son nom. Lu dans
     * le fichier d'origine s'il est encore là (dossier des envois seulement),
     * sinon dans le texte gardé par le message.
     *
     * @param list<array> $attachments rendu par sheetAttachments()
     * @return array{columns: list<array>, rows: list<array<string,string>>, notes: list<string>, truncated: bool, stopped: bool, file: string}
     */
    public static function attachmentSheet(array $attachments, string $wanted, string $sheet, ?string $uploadRoot = null): array
    {
        $file = self::pickAttachment($attachments, $wanted);
        $name = (string)$file['nom'];
        $path = self::confinedPath((string)$file['path'], $uploadRoot);
        if ($path !== null) {
            return self::fromGrid(LoopFiles::sheet($path, (string)$file['type'], $name, $sheet), $name);
        }
        return self::fromGrid(self::gridFromExtracted((string)$file['extracted'], $sheet), $name);
    }

    /** @throws \RuntimeException fichier introuvable ou ambigu (les classeurs joints sont nommés) */
    public static function pickAttachment(array $attachments, string $wanted): array
    {
        $list = implode(', ', array_map(static fn(array $f): string => $f['numero'] . '. ' . $f['nom'], $attachments));
        if ($attachments === []) {
            throw new \RuntimeException('Aucun classeur (Excel, CSV) n\'a été joint dans cette conversation.');
        }
        $wanted = trim($wanted);
        if ($wanted === '') {
            if (count($attachments) === 1) {
                return $attachments[0];
            }
            throw new \RuntimeException('Indique le classeur à lire, par son nom ou son numéro : ' . $list . '.');
        }
        if (preg_match('/^(?:n°|no|#)?\s*(\d{1,3})$/iu', $wanted, $m) === 1) {
            foreach ($attachments as $f) {
                if ((int)$f['numero'] === (int)$m[1]) {
                    return $f;
                }
            }
        }
        $folded = WorkTableOps::fold($wanted);
        $exact  = array_values(array_filter($attachments, static fn(array $f): bool => WorkTableOps::fold($f['nom']) === $folded));
        if (count($exact) === 1) {
            return $exact[0];
        }
        $partial = array_values(array_filter($attachments, static fn(array $f): bool => $folded !== '' && str_contains(WorkTableOps::fold($f['nom']), $folded)));
        if (count($partial) === 1) {
            return $partial[0];
        }
        throw new \RuntimeException('Classeur « ' . $wanted . ' » ' . ($partial === [] ? 'introuvable' : 'ambigu')
            . ' parmi les fichiers joints : ' . $list . '.');
    }

    /** Chemin réel d'un fichier joint, seulement s'il est un fichier du dossier des envois. */
    private static function confinedPath(string $path, ?string $uploadRoot): ?string
    {
        if ($path === '') {
            return null;
        }
        $root = realpath($uploadRoot ?? (defined('UPLOAD_DIR') ? UPLOAD_DIR : ''));
        $real = realpath($path);
        if ($root === false || $root === '' || $real === false || !is_file($real) || !str_starts_with($real, rtrim($root, '/') . '/')) {
            return null;
        }
        return $real;
    }

    /**
     * Grille lue dans le texte extrait d'un classeur (« # onglet » puis des
     * lignes à tabulations ; un CSV : son texte).
     */
    private static function gridFromExtracted(string $text, string $sheet): array
    {
        $sections = preg_split('/^# (.+)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        if (count($sections) >= 3) {
            // [avant, nom1, corps1, nom2, corps2…]
            $body  = (string)$sections[2];
            $names = [];
            for ($i = 1; $i + 1 < count($sections); $i += 2) {
                $names[] = trim((string)$sections[$i]);
                if ($sheet !== '' && WorkTableOps::fold((string)$sections[$i]) === WorkTableOps::fold($sheet)) {
                    $body = (string)$sections[$i + 1];
                }
            }
            $grid = array_map(static fn(string $l): array => explode("\t", $l), array_values(array_filter(explode("\n", trim($body)), static fn(string $l): bool => trim($l) !== '')));
            return LoopFiles::grid($names, '', $grid, 0);
        }
        try {
            $grid = TabularData::parse($text)->rows();
        } catch (\Throwable) {
            throw new \RuntimeException('Ce classeur est trop grand pour être relu depuis la conversation : joignez-le à nouveau.');
        }
        return LoopFiles::grid([], '', $grid, 0);
    }

    /** Grille de LoopFiles (en-tête + lignes) → colonnes et lignes d'une table. */
    private static function fromGrid(array $grid, string $fileName): array
    {
        $columns = WorkTableOps::columns((array)($grid['header'] ?? []));
        if ($columns === []) {
            throw new \RuntimeException('« ' . $fileName . ' » ne contient aucune ligne lisible.');
        }
        $all   = (array)($grid['rows'] ?? []);
        $rows  = WorkTableOps::rows($columns, array_slice($all, 0, WorkTableOps::MAX_ROWS));
        $notes = [];
        if (count($all) > WorkTableOps::MAX_ROWS) {
            $notes[] = 'Le classeur compte ' . count($all) . ' lignes : seules les ' . WorkTableOps::MAX_ROWS . ' premières sont dans la table.';
        }
        $sheets = array_values(array_filter((array)($grid['sheets'] ?? []), 'is_string'));
        if (count($sheets) > 1) {
            $notes[] = 'Onglet lu : « ' . (string)($grid['sheet'] ?? $sheets[0]) . ' ». Autres onglets : '
                . implode(', ', array_slice(array_values(array_diff($sheets, [(string)($grid['sheet'] ?? '')])), 0, 12)) . ' (paramètre `feuille`).';
        }
        return ['columns' => $columns, 'rows' => $rows, 'notes' => $notes,
            'truncated' => count($all) > WorkTableOps::MAX_ROWS, 'stopped' => false, 'file' => $fileName];
    }
}
