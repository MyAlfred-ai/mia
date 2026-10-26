<?php

declare(strict_types=1);

/**
 * Ce que fait une boucle « pour chaque » au moment de l'exécution : DÉCOUPER
 * sa source en éléments (split), puis COMPLÉTER un élément au moment de son
 * tour (fetch : le fichier d'une pièce jointe ou d'un dossier Drive, le
 * contenu d'un email).
 *
 * La liste est découpée UNE fois, au démarrage, et figée au dossier
 * (`<boucle>~items`) : une reprise, « Refaire les éléments en échec », un
 * rechargement voient exactement les mêmes éléments. Les contenus lourds ne
 * sont lus qu'au tour qui les traite : rien n'est stocké en double, et un
 * fichier disparu entre-temps met CET élément en échec, pas la boucle.
 *
 * Élément : { label, fields (clé → valeur brute), text (ce que le modèle lit
 * en plus des champs), cells (ligne d'origine), ref (de quoi le relire),
 * file (nom, type, taille ; chemin une fois lu) }.
 *
 * Tout lève une \RuntimeException au message présentable.
 */
final class LoopRunner
{
    /** Substitut des services connecteurs (tests) : fn(string $connecteur, int $userId) → objet. */
    private static ?\Closure $services = null;

    public static function overrideServices(?\Closure $fn): void
    {
        self::$services = $fn;
    }

    /**
     * Les éléments d'une boucle, dans l'ordre de la source (avant filtre).
     *
     * @param array $artifacts dossier vu par la boucle (WorkflowLoop::view)
     * @return array{elements: list<array>, header: list<string>, notes: list<string>}
     */
    public static function split(array $def, array $loop, array $artifacts, array $user, array $billing): array
    {
        $source = (array)($loop['source'] ?? []);
        $kind = (string)($source['kind'] ?? '');
        $pieces = WorkflowSpec::pieces($def);
        $over = $pieces[$loop['over']] ?? null;
        $art = $artifacts[$loop['over']] ?? null;
        $content = (array)($art['content'] ?? []);
        $needsPiece = LoopSources::readsPiece($source);
        if ($needsPiece && ($over === null || $art === null)) {
            throw new \RuntimeException(t('La pièce à parcourir n’a pas été remise : rien à découper.'));
        }
        $fill = static fn(string $text): string => trim((string)preg_replace('/\s+/u', ' ', WorkflowDeliveries::fill($text, $pieces, $artifacts, true, 300)));
        $userId = (int)$user['id'];

        // Fichier arrivé par le déclencheur « un fichier arrive dans un dossier
        // Drive » : la pièce n'en garde que le texte et l'identifiant — le
        // fichier lui-même est relu le temps du découpage.
        if ($needsPiece && ($over['form'] ?? '') === 'file' && (string)($content['path'] ?? '') === '' && (string)($content['drive_id'] ?? '') !== ''
            && in_array($kind, LoopSources::DRIVE_FILE_KINDS, true)) {
            return self::withDriveFile((string)$content['drive_id'], $userId, static function (string $path, string $mime, string $name) use ($def, $loop, $artifacts, $art, $content, $user, $billing): array {
                $artifacts[$loop['over']] = ['content' => ['path' => $path, 'type' => $mime, 'name' => $name, 'drive_id' => ''] + $content] + (array)$art;
                return self::split($def, $loop, $artifacts, $user, $billing);
            });
        }

        switch ($kind) {
            case LoopSources::LIST:
                return ['elements' => self::listElements($over, $content, (string)$loop['over_field']), 'header' => [], 'notes' => []];

            case LoopSources::ROWS:
                $grid = self::rowsGrid($source, $over, $content, $fill, $userId);
                return self::rowElements($loop, $grid);

            case LoopSources::SLIDES:
                return ['elements' => array_map(static fn(array $s): array => [
                    'label'  => $s['titre'] !== '' ? $s['titre'] : t('Slide {n}', ['n' => $s['numero']]),
                    'fields' => $s,
                    'text'   => '',
                    'ref'    => ['numero' => $s['numero']],
                ], self::slidesOf($source, $over, $content, $fill, $userId)), 'header' => [], 'notes' => []];

            case LoopSources::PAGES:
                return ['elements' => array_map(static fn(array $p): array => [
                    'label'  => t('Page {n}', ['n' => $p['numero']]),
                    'fields' => $p,
                    'text'   => '',
                    'ref'    => ['numero' => $p['numero']],
                ], self::pagesOf($source, $over, $content, $fill, $userId)), 'header' => [], 'notes' => []];

            case LoopSources::SECTIONS:
                $out = [];
                foreach (self::sectionsSource($source, $over, $content, $fill, $userId) as $i => $s) {
                    $out[] = ['label' => $s['titre'], 'fields' => $s, 'text' => '', 'ref' => ['numero' => $i + 1]];
                }
                return ['elements' => $out, 'header' => [], 'notes' => []];

            case LoopSources::ATTACHMENTS:
                return self::attachmentElements($def, $source, $content, $fill, $userId);

            case LoopSources::FILES:
                return self::folderElements($source, $fill, $userId);

            case LoopSources::EMAILS:
                return self::emailElements($source, $fill, $userId);

            case LoopSources::AI:
                return self::aiElements($def, $loop, $over, $content, $artifacts, $pieces, $billing);
        }
        throw new \RuntimeException(t('Cette façon de parcourir n’est pas connue.'));
    }

    // ==================================================================
    // Listes, tableaux
    // ==================================================================

    private static function listElements(array $over, array $content, string $field): array
    {
        $items = match ($over['form']) {
            'list'  => array_map('strval', (array)($content['items'] ?? [])),
            'fiche' => (static function () use ($content, $field): array {
                $v = $content['fields'][$field] ?? null;
                return is_array($v) ? array_map('strval', $v) : (WorkflowValues::listOf($v) ?? []);
            })(),
            default => LoopFiles::lines(ArtifactContract::render($over, $content, 400000)),
        };
        $out = [];
        foreach ($items as $it) {
            $it = trim($it);
            if ($it !== '') {
                $out[] = ['label' => WorkflowLoop::label($it), 'fields' => [], 'text' => mb_substr($it, 0, WorkflowSpec::ITEM_MAX)];
            }
        }
        return $out;
    }

    /** En-tête et lignes de la source d'une boucle « pour chaque ligne ». */
    private static function rowsGrid(array $source, ?array $over, array $content, \Closure $fill, int $userId): array
    {
        $sheet = (string)($source['sheet'] ?? '');
        $header = (int)($source['header'] ?? 0);
        if (($source['from'] ?? '') === LoopSources::FROM_DRIVE) {
            return self::withDriveFile($fill((string)$source['drive']), $userId, static function (string $path, string $mime, string $name) use ($sheet, $header): array {
                if (!LoopFiles::isSheet($mime, $name)) {
                    throw new \RuntimeException(t('« {nom} » n’est pas un classeur : désignez un fichier Excel, CSV ou Google Sheets.', ['nom' => $name]));
                }
                return LoopFiles::sheet($path, $mime, $name, $sheet, $header);
            });
        }
        switch ($over['form']) {
            case 'table':
                return LoopFiles::grid([], '', (array)($content['rows'] ?? []), max(1, $header));
            case 'document':
                try {
                    $grid = TabularData::parse((string)($content['source'] ?? ''))->rows();
                } catch (\Throwable) {
                    $grid = [];
                }
                return LoopFiles::grid([], '', $grid, $header);
            case 'file':
                $name = (string)($content['name'] ?? '');
                $mime = (string)($content['type'] ?? '');
                if (!LoopFiles::isSheet($mime, $name)) {
                    throw new \RuntimeException(t('« {nom} » n’est pas un classeur : joignez un fichier Excel (.xlsx) ou CSV.', ['nom' => $name]));
                }
                return LoopFiles::sheet((string)($content['path'] ?? ''), $mime, $name, $sheet, $header);
        }
        throw new \RuntimeException(t('« {nom} » ne se parcourt pas ligne par ligne.', ['nom' => (string)($over['name'] ?? '')]));
    }

    /** Nom normalisé d'une colonne : sans majuscules, accents, espaces ni ponctuation. */
    public static function columnName(string $label): string
    {
        $s = mb_strtolower(trim($label));
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae']);
        return (string)preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /**
     * Lignes → éléments. Les colonnes DÉCLARÉES se retrouvent dans l'en-tête
     * par leur nom ; une colonne déclarée absente arrête la boucle avant tout
     * tour (rien n'est dépensé), en disant les colonnes trouvées.
     */
    public static function rowElements(array $loop, array $grid): array
    {
        $columns = (array)($loop['source']['columns'] ?? []);
        $header = (array)($grid['header'] ?? []);
        $byName = [];
        foreach ($header as $i => $h) {
            $byName[self::columnName((string)$h)] ??= $i;
        }
        $index = [];
        $missing = [];
        foreach ($columns as $c) {
            $i = $byName[self::columnName((string)$c['label'])] ?? null;
            if ($i === null) {
                $missing[] = (string)$c['label'];
            } else {
                $index[$c['key']] = $i;
            }
        }
        if ($missing !== [] && $grid['rows'] !== []) {
            throw new \RuntimeException(t('Colonne absente du fichier : {manquantes}. Colonnes trouvées : {trouvees}. Corrigez les colonnes de la boucle dans l’éditeur, publiez, puis reprenez.', [
                'manquantes' => I18n::listeCitee($missing),
                'trouvees'   => $header !== [] ? implode(', ', $header) : t('aucune'),
            ]));
        }
        $labelKey = (string)($loop['source']['label'] ?? '');
        $labelIdx = $labelKey !== '' && isset($index[$labelKey]) ? $index[$labelKey] : (reset($index) !== false ? reset($index) : null);
        $declared = array_flip(array_values($index));
        $elements = [];
        foreach ((array)$grid['rows'] as $n => $row) {
            $fields = [];
            foreach ($index as $key => $i) {
                $fields[$key] = (string)($row[$i] ?? '');
            }
            $label = $labelIdx !== null ? trim((string)($row[$labelIdx] ?? '')) : '';
            if ($labelIdx === null) {
                foreach ($row as $cell) {
                    if (trim((string)$cell) !== '') {
                        $label = trim((string)$cell);
                        break;
                    }
                }
            }
            $lines = [];
            foreach ($header as $i => $h) {
                $v = trim((string)($row[$i] ?? ''));
                if ($v !== '' && ($declared === [] || !isset($declared[$i]))) {
                    $lines[] = $h . ' : ' . $v;
                }
            }
            $elements[] = [
                'label'  => mb_substr($label, 0, 200),
                'fields' => $fields,
                'text'   => $lines !== [] ? mb_substr(($declared !== [] ? t('Autres colonnes :') . "\n" : '') . implode("\n", $lines), 0, WorkflowSpec::ITEM_MAX) : '',
                'cells'  => array_map('strval', (array)$row),
                'ref'    => ['ligne' => (int)$n + 1 + (int)($grid['header_row'] ?? 1)],
            ];
        }
        $notes = [];
        if (($grid['sheet'] ?? '') !== '' && count((array)($grid['sheets'] ?? [])) > 1) {
            $notes[] = t('Onglet « {onglet} ».', ['onglet' => $grid['sheet']]);
        }
        return ['elements' => $elements, 'header' => $header, 'notes' => $notes];
    }

    // ==================================================================
    // Présentations, PDF, documents
    // ==================================================================

    private static function slidesOf(array $source, ?array $over, array $content, \Closure $fill, int $userId): array
    {
        if (($source['from'] ?? '') === LoopSources::FROM_DRIVE) {
            return self::withDriveFile($fill((string)$source['drive']), $userId, static function (string $path, string $mime, string $name): array {
                if (!LoopFiles::isSlides($mime, $name)) {
                    throw new \RuntimeException(t('« {nom} » n’est pas une présentation : désignez un PowerPoint (.pptx) ou une présentation Google.', ['nom' => $name]));
                }
                return LoopFiles::slides($path, $name);
            });
        }
        if ($over['form'] === 'document') {
            return LoopFiles::slidesFromMarkdown((string)($content['source'] ?? ''));
        }
        $name = (string)($content['name'] ?? '');
        if (!LoopFiles::isSlides((string)($content['type'] ?? ''), $name)) {
            throw new \RuntimeException(t('« {nom} » n’est pas une présentation : joignez un PowerPoint (.pptx).', ['nom' => $name]));
        }
        return LoopFiles::slides((string)($content['path'] ?? ''), $name);
    }

    private static function pagesOf(array $source, ?array $over, array $content, \Closure $fill, int $userId): array
    {
        if (($source['from'] ?? '') === LoopSources::FROM_DRIVE) {
            return self::withDriveFile($fill((string)$source['drive']), $userId, static function (string $path, string $mime, string $name): array {
                if (!LoopFiles::isPdf($mime, $name)) {
                    throw new \RuntimeException(t('« {nom} » n’est pas un PDF.', ['nom' => $name]));
                }
                return LoopFiles::pages($path, $name);
            });
        }
        $name = (string)($content['name'] ?? '');
        if (!LoopFiles::isPdf((string)($content['type'] ?? ''), $name)) {
            throw new \RuntimeException(t('« {nom} » n’est pas un PDF.', ['nom' => $name]));
        }
        return LoopFiles::pages((string)($content['path'] ?? ''), $name);
    }

    private static function sectionsSource(array $source, ?array $over, array $content, \Closure $fill, int $userId): array
    {
        if (($source['from'] ?? '') === LoopSources::FROM_DRIVE) {
            return self::withDriveFile($fill((string)$source['drive']), $userId, static function (string $path, string $mime, string $name): array {
                return LoopFiles::sectionsOf(LoopFiles::documentMarkdown($path, $mime, $name));
            });
        }
        return match ($over['form']) {
            'document' => LoopFiles::sectionsOf((string)($content['source'] ?? '')),
            'file'     => LoopFiles::sectionsOf(LoopFiles::documentMarkdown((string)($content['path'] ?? ''), (string)($content['type'] ?? ''), (string)($content['name'] ?? ''))),
            default    => LoopFiles::sectionsOf(ArtifactContract::render($over, $content, 400000)),
        };
    }

    // ==================================================================
    // Pièces jointes, dossiers, emails
    // ==================================================================

    private static function attachmentElements(array $def, array $source, array $content, \Closure $fill, int $userId): array
    {
        $types = (string)($source['types'] ?? 'all');
        $inline = !empty($source['inline']);
        $messages = [];
        if (($source['from'] ?? LoopSources::FROM_PIECE) === LoopSources::FROM_SEARCH) {
            $service = (string)($source['service'] ?? 'gmail');
            $svc = self::mailService($service, $userId);
            foreach (self::searchMessages($svc, $service, $source, $fill, true) as $m) {
                $messages[] = ['service' => $service, 'id' => (string)$m['id'], 'objet' => (string)($m['objet'] ?? ''), 'de' => (string)($m['de'] ?? '')];
            }
        } else {
            $id = (string)($content['message_id'] ?? '');
            if ($id === '') {
                // Lancé à la main avec un texte collé : aucun email réel, aucune pièce jointe.
                return ['elements' => [], 'header' => [], 'notes' => [t('L’email reçu n’a pas de pièce jointe lisible ici (essai lancé à la main).')]];
            }
            $service = (string)($content['service'] ?? ($def['start']['event']['source'] ?? 'gmail'));
            $messages[] = ['service' => $service, 'id' => $id, 'objet' => (string)($content['fields']['objet'] ?? ''), 'de' => (string)($content['fields']['expediteur'] ?? '')];
        }
        $out = [];
        $skippedInline = 0;
        foreach ($messages as $m) {
            $svc = self::mailService($m['service'], $userId);
            if ($m['service'] === 'outlook') {
                $list = $svc->listAttachments($m['id']);
            } else {
                $res = $svc->listAttachments($m['id']);
                $list = $res['pieces'];
                $m['objet'] = $m['objet'] !== '' ? $m['objet'] : $res['objet'];
                $m['de'] = $m['de'] !== '' ? $m['de'] : $res['de'];
            }
            foreach ($list as $a) {
                if (($a['piece'] ?? 'fichier') !== 'fichier') {
                    continue;
                }
                if (!$inline && !empty($a['en_ligne'])) {
                    $skippedInline++;
                    continue;
                }
                if (!LoopSources::attachmentKept((string)$a['type'], (string)$a['nom'], $types)) {
                    continue;
                }
                $out[] = [
                    'label'  => (string)$a['nom'],
                    'fields' => [
                        'nom' => (string)$a['nom'], 'type' => MailAttachments::humanType((string)$a['type'], (string)$a['nom']),
                        'taille' => MailAttachments::humanSize((int)$a['taille']), 'email_objet' => $m['objet'],
                        'email_de' => UserGmailService::extractAddress($m['de']) ?: $m['de'],
                    ],
                    'text'   => t('Pièce jointe de l’email « {objet} » de {de}.', ['objet' => $m['objet'] !== '' ? $m['objet'] : t('(sans objet)'), 'de' => $m['de'] !== '' ? $m['de'] : '—']),
                    'ref'    => ['service' => $m['service'], 'message' => $m['id'], 'numero' => (int)$a['numero'], 'nom' => (string)$a['nom']],
                    'file'   => ['name' => (string)$a['nom'], 'type' => (string)$a['type'], 'size' => (int)$a['taille']],
                ];
            }
        }
        $notes = $skippedInline > 0 ? [I18n::plural($skippedInline, '{n} image intégrée au corps (logo, signature) écartée.', '{n} images intégrées au corps (logos, signatures) écartées.')] : [];
        return ['elements' => $out, 'header' => [], 'notes' => $notes];
    }

    private static function folderElements(array $source, \Closure $fill, int $userId): array
    {
        $svc = self::driveService($userId);
        $res = $svc->folderFiles($fill((string)($source['drive'] ?? '')), LoopSources::MAX_FOLDER_FILES);
        $name = WorkflowLoop::fold((string)($source['name'] ?? ''));
        $kind = (string)($source['file_kind'] ?? '');
        $out = [];
        foreach ($res['fichiers'] as $f) {
            if ($name !== '' && !str_contains(WorkflowLoop::fold($f['nom']), $name)) {
                continue;
            }
            if ($kind !== '' && !SearchScope::kindMatches($kind, $f['type'])) {
                continue;
            }
            $out[] = [
                'label'  => $f['nom'],
                'fields' => ['nom' => $f['nom'], 'type' => $f['type'], 'modifie_le' => substr((string)$f['modifie'], 0, 10), 'lien' => $f['lien']],
                'text'   => t('Fichier du dossier « {dossier} ».', ['dossier' => $res['dossier']['nom']]),
                'ref'    => ['drive' => $f['id'], 'mime' => $f['mime']],
                'file'   => ['name' => $f['nom'], 'type' => $f['mime'], 'size' => (int)($f['taille'] ?? 0)],
            ];
        }
        $notes = $res['total'] > LoopSources::MAX_FOLDER_FILES
            ? [t('Le dossier compte {total} fichiers : les {n} premiers par nom sont lus.', ['total' => $res['total'], 'n' => LoopSources::MAX_FOLDER_FILES])]
            : [];
        return ['elements' => $out, 'header' => [], 'notes' => $notes];
    }

    private static function emailElements(array $source, \Closure $fill, int $userId): array
    {
        $service = (string)($source['service'] ?? 'gmail');
        $svc = self::mailService($service, $userId);
        $out = [];
        foreach (self::searchMessages($svc, $service, $source, $fill, false) as $m) {
            $from = (string)($m['de'] ?? '');
            $out[] = [
                'label'  => (string)($m['objet'] ?? '') !== '' ? (string)$m['objet'] : t('(sans objet)'),
                'fields' => [
                    'expediteur' => UserGmailService::extractAddress($from) ?: $from, 'objet' => (string)($m['objet'] ?? ''),
                    'recu_le' => (string)(WorkflowValues::date((string)($m['date'] ?? '')) ?? ''), 'contenu' => (string)($m['extrait'] ?? ''),
                    'pieces_jointes' => [],
                ],
                'text'   => t('Email de {de}.', ['de' => $from !== '' ? $from : '—']),
                'ref'    => ['service' => $service, 'message' => (string)$m['id']],
            ];
        }
        return ['elements' => $out, 'header' => [], 'notes' => []];
    }

    /** Emails d'une recherche : requête, période, non lus (et pièce jointe, pour les pièces jointes). */
    private static function searchMessages(object $svc, string $service, array $source, \Closure $fill, bool $withAttachment): array
    {
        $query = $fill((string)($source['query'] ?? ''));
        $scope = SearchScope::fromFilters(['mail' => [
            'from' => '', 'subject' => '', 'words' => '', 'attachment' => $withAttachment,
            'unread' => !empty($source['unread']), 'days' => (int)($source['days'] ?? 0),
        ]]);
        if ($service === 'outlook') {
            return $scope->filterOutlook($svc->searchMany($scope->outlookQuery($query), LoopSources::MAX_EMAILS));
        }
        return $svc->searchMany($scope->gmailQuery($query), LoopSources::MAX_EMAILS);
    }

    // ==================================================================
    // Repérés par MIA
    // ==================================================================

    private static function aiElements(array $def, array $loop, array $over, array $content, array $artifacts, array $pieces, array $billing): array
    {
        $source = (array)$loop['source'];
        $instruction = WorkflowSpec::renderMentions((string)($source['instruction'] ?? ''), $pieces);
        $evidence = $over['form'] === 'file'
            ? (string)($content['text'] ?? '')
            : ArtifactContract::render($over, $content, 60000);
        if (trim($evidence) === '') {
            throw new \RuntimeException(t('« {nom} » ne contient aucun texte lisible : MIA n’a rien à y repérer.', ['nom' => (string)$over['name']]));
        }
        $res = WorkflowAi::extract($instruction, (array)($source['fields'] ?? []), (string)$def['objective'], (string)$over['name'], mb_substr($evidence, 0, 60000), $billing);
        if ($res['error'] !== null) {
            throw new LoopSourceException($res['error']);
        }
        $out = [];
        foreach ($res['items'] as $it) {
            $out[] = ['label' => $it['label'], 'fields' => $it['fields'], 'text' => $it['text']];
        }
        return ['elements' => $out, 'header' => [], 'notes' => []];
    }

    // ==================================================================
    // Au moment du tour
    // ==================================================================

    /**
     * Complète un élément pour son tour : le fichier d'une pièce jointe ou
     * d'un dossier (enregistré parmi les téléversements, joint au message de
     * l'étape), le contenu entier d'un email.
     */
    public static function fetch(array $loop, array $element, array $user): array
    {
        $kind = (string)($loop['source']['kind'] ?? '');
        $ref = (array)($element['ref'] ?? []);
        $userId = (int)$user['id'];
        if ($kind === LoopSources::ATTACHMENTS) {
            $svc = self::mailService((string)($ref['service'] ?? 'gmail'), $userId);
            $file = $svc->downloadAttachment((string)$ref['message'], (string)($ref['nom'] ?? (string)($ref['numero'] ?? '')));
            $element['file'] = self::storeFile($file['nom'], $file['bytes']);
            return $element;
        }
        if ($kind === LoopSources::FILES) {
            $file = self::driveService($userId)->downloadFile((string)$ref['drive']);
            $element['file'] = self::storeFile($file['nom'], $file['bytes']);
            return $element;
        }
        if ($kind === LoopSources::EMAILS) {
            $svc = self::mailService((string)($ref['service'] ?? 'gmail'), $userId);
            $msg = $svc->readMessage((string)$ref['message'], 6000);
            $element['fields']['contenu'] = mb_substr((string)($msg['contenu'] ?? ''), 0, LoopFiles::PART_MAX);
            $element['fields']['pieces_jointes'] = array_values(array_map(static fn($a): string => (string)($a['nom'] ?? ''), (array)($msg['pieces_jointes'] ?? [])));
            return $element;
        }
        return $element;
    }

    /**
     * Un fichier reçu pendant la boucle, parmi les téléversements (lisible
     * par l'étape, gardé tant que l'exécution le cite) ; son texte extrait
     * pour un modèle qui ne lit pas le format.
     *
     * @return array{name: string, type: string, size: int, path: string, text: string}
     */
    public static function storeFile(string $name, string $bytes): array
    {
        if ($bytes === '') {
            throw new \RuntimeException(t('Le fichier « {nom} » est vide.', ['nom' => $name]));
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        $path = UPLOAD_DIR . 'wf_' . bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        if (@file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException(t('Le fichier « {nom} » n’a pas pu être enregistré.', ['nom' => $name]));
        }
        $mime = FileExtractor::detectMime($path, $name);
        $native = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
        $text = $native ? '' : (string)(FileExtractor::extract($path, $mime) ?? '');
        return ['name' => mb_substr($name, 0, 200), 'type' => $mime, 'size' => strlen($bytes), 'path' => $path, 'text' => mb_substr($text, 0, ArtifactContract::PROMPT_CHARS)];
    }

    // ==================================================================
    // Services
    // ==================================================================

    private static function mailService(string $service, int $userId): object
    {
        if (self::$services !== null) {
            return (self::$services)($service, $userId);
        }
        $gmail = $service !== 'outlook';
        $enabled = $gmail ? UserGmailService::isGloballyEnabled() : UserOutlookService::isGloballyEnabled();
        $svc = $enabled ? ($gmail ? UserGmailService::forUser($userId) : UserOutlookService::forUser($userId)) : null;
        if ($svc === null || !$svc->canRead()) {
            throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => $gmail ? 'Gmail' : 'Outlook']));
        }
        return $svc;
    }

    private static function driveService(int $userId): object
    {
        if (self::$services !== null) {
            return (self::$services)('drive', $userId);
        }
        $svc = UserDriveService::isGloballyEnabled() ? UserDriveService::forUser($userId) : null;
        if ($svc === null) {
            throw new \RuntimeException(t('{service} non connecté — reconnectez-le dans Réglages → Comptes connectés.', ['service' => 'Google Drive']));
        }
        return $svc;
    }

    /** Un fichier Drive, téléchargé dans un fichier temporaire le temps de le lire. */
    private static function withDriveFile(string $ref, int $userId, \Closure $read): array
    {
        if ($ref === '') {
            throw new \RuntimeException(t('Indiquez le fichier Google Drive à lire : son lien, son nom ou son chemin.'));
        }
        $file = self::driveService($userId)->downloadFile($ref);
        $tmp = tempnam(sys_get_temp_dir(), 'wfloop_');
        if ($tmp === false) {
            throw new \RuntimeException(t('Écriture temporaire impossible sur le serveur.'));
        }
        $ext = strtolower(pathinfo((string)$file['nom'], PATHINFO_EXTENSION));
        $path = $tmp . ($ext !== '' ? '.' . $ext : '');
        @rename($tmp, $path);
        try {
            file_put_contents($path, $file['bytes']);
            return $read($path, (string)$file['mime'], (string)$file['nom']);
        } finally {
            @unlink($path);
            @unlink($tmp);
        }
    }
}

/** Une panne présentée (titre, message, genre) remontée par un découpeur. */
final class LoopSourceException extends \RuntimeException
{
    public function __construct(public readonly array $error)
    {
        parent::__construct((string)($error['message'] ?? ''));
    }
}
