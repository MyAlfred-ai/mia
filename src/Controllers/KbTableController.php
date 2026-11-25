<?php

declare(strict_types=1);

/**
 * Tables PERMANENTES (bases de connaissances de nature « table ») :
 *
 *   - `POST /admin/knowledge-bases/table`                       créer (fichier ou colonnes saisies) ;
 *   - `GET  /admin/knowledge-bases/{id}/table/rows`             la fiche (grille, filtre, page ; `version` = un état passé) ;
 *   - `POST /admin/knowledge-bases/{id}/table/cell`             corriger une cellule ;
 *   - `POST /admin/knowledge-bases/{id}/table/row`              ajouter une ligne ;
 *   - `POST /admin/knowledge-bases/{id}/table/row-delete`       supprimer une ligne ;
 *   - `POST /admin/knowledge-bases/{id}/table/column`           ajouter une colonne ;
 *   - `POST /admin/knowledge-bases/{id}/table/column-update`    la renommer, changer son type ;
 *   - `POST /admin/knowledge-bases/{id}/table/column-delete`    la supprimer ;
 *   - `POST /admin/knowledge-bases/{id}/table/import`           remplacer par un fichier, ou en ajouter les lignes ;
 *   - `GET  /admin/knowledge-bases/{id}/table/history`          le volet « Historique » (`version` = l'état ouvert) ;
 *   - `POST /admin/knowledge-bases/{id}/table/history/restore`  revenir à un état ;
 *   - `POST /admin/knowledge-bases/{id}/table/history/undo`     annuler ce retour ;
 *   - `GET  /admin/knowledge-bases/{id}/table/export/{fichier}` télécharger (Excel ou CSV).
 *
 * Le nom, la description, le partage, les assistants liés et « MIA peut y
 * écrire » passent par la fiche commune des bases (AdminController::
 * updateKnowledgeBase). Ici, seulement les lignes.
 *
 * Mêmes portes que les bases : lire = « Bases de connaissances — Voir » et
 * une base que la personne gère ; écrire = « Modifier ». Contrôleur mince :
 * le métier est dans KbTableStore, KbTableImport et KbTableView. La fiche est
 * renvoyée toute rendue : le script de la page n'a aucun libellé.
 */
class KbTableController
{
    /** Crée une table depuis un classeur déposé, ou des colonnes saisies. */
    public function create(): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.create');
        $user = Session::currentUser();
        $back = '/admin/knowledge-bases/new?genre=table';
        try {
            $import = (string)($_POST['table_source'] ?? 'file') === 'manual'
                ? KbTableImport::fromHeaders($_POST['columns'] ?? '')
                : KbTableImport::fromUpload(is_array($_FILES['file'] ?? null) ? $_FILES['file'] : []);
            $visibility = AdminController::postedKbVisibility();
            $store = new KbTableStore();
            $kbId  = $store->createBase(
                $user,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                $import,
                $visibility === KnowledgeBase::VIS_PRIVATE,
                $visibility === KnowledgeBase::VIS_PUBLIC
            );
            // La fiche de création est celle de la modification : ce que MIA
            // peut y faire, ses assistants et son partage partent avec elle.
            $store->setMiaWrite($kbId, !empty($_POST['mia_write']));
            KnowledgeBase::setProfiles(
                $kbId,
                is_array($_POST['profile_ids'] ?? null) ? $_POST['profile_ids'] : [],
                array_map(static fn(array $p): int => (int)$p['id'], Profile::forManager($user))
            );
            if ($visibility === KnowledgeBase::VIS_COMMON || $visibility === null) {
                KnowledgeBase::setShares(
                    $kbId,
                    self::postedShareMap('share_user_ids', 'share_user_manage'),
                    self::postedShareMap('share_group_ids', 'share_group_manage')
                );
            }
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . $back);
            exit;
        }
        error_log('[Tables] table permanente #' . $kbId . ' créée par #' . (int)$user['id']
            . ' (' . count($import['rows']) . ' lignes, ' . (string)($import['origin']['kind'] ?? '') . ')');
        Session::flash('success', implode(' ', array_merge(
            [t('Table créée : {lignes}.', ['lignes' => WorkTableView::rowsLabel(count($import['rows']))])],
            $import['notes']
        )));
        header('Location: /admin/knowledge-bases/' . $kbId);
        exit;
    }

    /**
     * Partages postés par la fiche de création : id coché → droit d'écriture
     * (1) ou non (0). Les bornes et les doublons sont l'affaire de setShares.
     *
     * @return array<int, int>
     */
    private static function postedShareMap(string $idsField, string $manageField): array
    {
        $ids    = array_filter(array_map('intval', (array)($_POST[$idsField] ?? [])), static fn(int $i): bool => $i > 0);
        $manage = array_flip(array_map('intval', (array)($_POST[$manageField] ?? [])));
        $map    = [];
        foreach ($ids as $id) {
            $map[$id] = isset($manage[$id]) ? 1 : 0;
        }
        return $map;
    }

    /** La fiche : colonnes, grille de la page demandée, origine — ou un état passé (`version`). */
    public function rows(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, false);
        if ($table === null) {
            return;
        }
        session_write_close();
        $this->respond($kb, $table, $store);
    }

    /** Corrige une cellule. La page n'est pas re-rendue : la valeur enregistrée lui revient. */
    public function cell(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $position = (int)($_POST['position'] ?? -1);
        $key      = (string)($_POST['key'] ?? '');
        $value    = WorkTableOps::cell($_POST['value'] ?? '');
        if (!$store->setCell((int)$kb['id'], $position, $key, $value, self::userId())) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette cellule n’existe plus. Rechargez la page.')], 404);
            return;
        }
        AjaxResponse::send(['ok' => true, 'value' => $value]);
    }

    /** Ajoute une ligne vide en fin de table, et montre la dernière page. */
    public function addRow(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        try {
            $position = $store->addRow((int)$kb['id'], [], self::userId());
        } catch (KbTableTooLargeException $e) {
            AjaxResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        $table = $store->find((int)$kb['id']) ?? $table;
        $this->respond($kb, $table, $store, [
            'focus' => (int)$position,
        ], '', (int)ceil(max(1, (int)$table['rows']) / WorkTableView::PAGE_ROWS));
    }

    public function deleteRow(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        if (!$store->deleteRow((int)$kb['id'], (int)($_POST['position'] ?? -1), self::userId())) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette ligne n’existe plus. Rechargez la page.')], 404);
            return;
        }
        $this->respond($kb, $store->find((int)$kb['id']) ?? $table, $store);
    }

    public function addColumn(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $label = self::label($_POST['label'] ?? '');
        if ($label === '') {
            AjaxResponse::send(['ok' => false, 'error' => t('Donnez un nom à la colonne.')], 422);
            return;
        }
        $columns = (array)$table['columns'];
        foreach ($columns as $c) {
            if (WorkTableOps::fold((string)$c['label']) === WorkTableOps::fold($label)) {
                AjaxResponse::send(['ok' => false, 'error' => t('Une colonne porte déjà ce nom.')], 422);
                return;
            }
        }
        $columns[] = ['label' => $label, 'type' => self::type($_POST['type'] ?? '')];
        try {
            $table = $store->setColumns((int)$kb['id'], $columns, self::userId()) ?? $table;
        } catch (KbTableTooLargeException $e) {
            AjaxResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        $this->respond($kb, $table, $store);
    }

    /** Renomme une colonne ou change son type : sa clé, donc ses valeurs, ne bougent pas. */
    public function updateColumn(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $key   = (string)($_POST['key'] ?? '');
        $label = self::label($_POST['label'] ?? '');
        if ($label === '') {
            AjaxResponse::send(['ok' => false, 'error' => t('Donnez un nom à la colonne.')], 422);
            return;
        }
        $columns = (array)$table['columns'];
        $found   = false;
        foreach ($columns as $i => $c) {
            if ((string)$c['key'] === $key) {
                $columns[$i]['label'] = $label;
                $columns[$i]['type']  = self::type($_POST['type'] ?? $c['type']);
                $found = true;
            } elseif (WorkTableOps::fold((string)$c['label']) === WorkTableOps::fold($label)) {
                AjaxResponse::send(['ok' => false, 'error' => t('Une colonne porte déjà ce nom.')], 422);
                return;
            }
        }
        if (!$found) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette colonne n’existe plus. Rechargez la page.')], 404);
            return;
        }
        $this->respond($kb, $store->setColumns((int)$kb['id'], $columns, self::userId()) ?? $table, $store);
    }

    /** Supprime une colonne et ses valeurs — écriture d'ensemble : l'état d'avant reste dans l'historique. */
    public function deleteColumn(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $key     = (string)($_POST['key'] ?? '');
        $columns = array_values(array_filter((array)$table['columns'], static fn(array $c): bool => (string)$c['key'] !== $key));
        if (count($columns) === count((array)$table['columns'])) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette colonne n’existe plus. Rechargez la page.')], 404);
            return;
        }
        if ($columns === []) {
            AjaxResponse::send(['ok' => false, 'error' => t('Une table garde au moins une colonne.')], 422);
            return;
        }
        $label = '';
        foreach ((array)$table['columns'] as $c) {
            if ((string)$c['key'] === $key) {
                $label = (string)$c['label'];
            }
        }
        $rows = array_map(static function (array $row) use ($key): array {
            unset($row[$key]);
            return $row;
        }, $store->rows((int)$kb['id']));
        $table = $store->replace((int)$kb['id'], $columns, $rows, null, self::userId(),
            KbTableHistory::change(KbTableHistory::KIND_COLUMN, ['dropped' => [$label]])) ?? $table;
        $this->respond($kb, $table, $store);
    }

    /**
     * Un classeur déposé : il REMPLACE le contenu (mode `replace`), ou ses
     * lignes s'AJOUTENT (mode `append` — ses colonnes sont rapprochées de
     * celles de la table par leur nom). L'état d'avant reste dans l'historique.
     */
    public function import(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $append = (string)($_POST['mode'] ?? 'replace') === 'append';
        try {
            $import = KbTableImport::fromUpload(is_array($_FILES['file'] ?? null) ? $_FILES['file'] : []);
            $file   = (string)($import['origin']['file'] ?? '');
            if ($append) {
                $spec = KbTableWrite::spec((array)$table['columns'], $import['columns'], ['mode' => KbTableWrite::MODE_APPEND]);
                $plan = KbTableWrite::plan((array)$table['columns'], $store->rows((int)$kb['id']), $import['columns'], $import['rows'], $spec);
                $table = $store->replace((int)$kb['id'], $plan['columns'], $plan['rows'], null, self::userId(),
                    KbTableHistory::change(KbTableHistory::KIND_IMPORT, [
                        'mode' => 'append', 'file' => $file, 'added' => (int)$plan['stats']['added'],
                    ])) ?? $table;
                $message = I18n::plural((int)$plan['stats']['added'], '{n} ligne ajoutée depuis {fichier}.', '{n} lignes ajoutées depuis {fichier}.', [
                    'n' => Format::credits((int)$plan['stats']['added']), 'fichier' => $file,
                ]);
            } else {
                $table = $store->replace((int)$kb['id'], $import['columns'], $import['rows'], $import['origin'], self::userId(),
                    KbTableHistory::change(KbTableHistory::KIND_IMPORT, [
                        'mode' => 'replace', 'file' => $file, 'sheet' => (string)($import['origin']['sheet'] ?? ''),
                    ])) ?? $table;
                $message = t('Contenu remplacé par {fichier} : {lignes}.', [
                    'fichier' => $file, 'lignes' => WorkTableView::rowsLabel(count($import['rows'])),
                ]);
            }
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            AjaxResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        error_log('[Tables] table permanente #' . (int)$kb['id'] . ' : fichier « ' . $file . ' » ' . ($append ? 'ajouté' : 'importé')
            . ' par #' . self::userId() . ' (' . (int)$table['rows'] . ' lignes)');
        $this->respond($kb, $table, $store, ['message' => implode(' ', array_merge([$message], $import['notes']))], '', 1);
    }

    /**
     * Le volet « Historique » : les états de la table, groupés par jour dans
     * le fuseau du navigateur (`tz`). `version` = l'état ouvert : il porte
     * alors ce qu'un retour changerait, et le bandeau de l'aperçu revient avec.
     */
    public function history(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, false);
        if ($table === null) {
            return;
        }
        $kbId     = (int)$kb['id'];
        $viewerId = self::userId();
        $canWrite = self::canWrite($kbId);
        session_write_close();

        $tz       = LogQuery::timeZone(is_scalar($_GET['tz'] ?? null) ? (string)$_GET['tz'] : '');
        $entries  = $store->history($kbId);
        $table    = $store->find($kbId) ?? $table;
        $current  = (int)$table['version'];
        $selected = (int)($_GET['version'] ?? 0);
        $chosen   = null;
        foreach ($entries as $e) {
            if ((int)$e['version'] === $selected) {
                $chosen = $e;
            }
        }
        if ($chosen === null) {
            $selected = 0;
        }
        $preview = null;
        if ($chosen !== null && $selected !== $current && !empty($chosen['rows_kept'])) {
            $diff    = self::diffWith($store, $kbId, $chosen, $table);
            $preview = [
                'version' => $selected,
                'moment'  => KbTableHistory::moment((int)$chosen['created'], $tz),
                'summary' => KbTableHistory::summary($diff),
                'confirm' => KbTableHistory::confirmText($diff),
            ];
        }
        $historyView = [
            'kb_id'    => $kbId,
            'groups'   => KbTableHistory::view($entries, $current, $viewerId, $tz, time(), $selected, $canWrite,
                KbTableHistory::undoableFrom($entries, $current, $viewerId)),
            'preview'  => $preview,
            'can_write'=> $canWrite,
        ];
        AjaxResponse::send([
            'ok'       => true,
            'html'     => self::render('table-history.php', ['historyView' => $historyView]),
            'banner'   => $preview !== null ? self::render('table-history-banner.php', ['historyView' => $historyView]) : '',
            'selected' => $preview !== null ? $selected : 0,
        ]);
    }

    /**
     * Revient à un état : ses lignes sont copiées dans un nouvel état. Un
     * formulaire ordinaire (il marche sans script) : la page se recharge, le
     * volet ouvert, avec « Annuler » dans la notification.
     */
    public function historyRestore(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $kbId    = (int)$kb['id'];
        $version = (int)($_POST['version'] ?? 0);
        $tz      = LogQuery::timeZone(is_scalar($_POST['tz'] ?? null) ? (string)$_POST['tz'] : '');
        $target  = $store->entry($kbId, $version);
        $back    = '/admin/knowledge-bases/' . $kbId . '?historique=1';
        try {
            if ($target === null) {
                throw new \RuntimeException(t('Cet état n’est plus conservé : on ne peut plus y revenir.'));
            }
            $store->restore($kbId, $version, self::userId());
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . $back);
            exit;
        }
        error_log('[Tables] table permanente #' . $kbId . ' revenue à l\'état ' . $version . ' par #' . self::userId());
        Session::flash('kb_table_restored', t('Table revenue à l’état du {moment}.', [
            'moment' => KbTableHistory::moment((int)$target['created'], $tz),
        ]));
        header('Location: ' . $back . '&retour=1');
        exit;
    }

    /** Annule le retour en arrière qu'on vient de faire (KbTableHistory::canUndo). */
    public function historyUndo(string $id): void
    {
        [$kb, $table, $store] = $this->open($id, true);
        if ($table === null) {
            return;
        }
        $kbId = (int)$kb['id'];
        if ($store->undoRestore($kbId, self::userId()) === null) {
            Session::flash('error', t('Ce retour en arrière ne peut plus être annulé. Revenez à l’état voulu depuis l’historique.'));
        } else {
            error_log('[Tables] table permanente #' . $kbId . ' : retour en arrière annulé par #' . self::userId());
            Session::flash('success', t('Retour annulé : la table a repris l’état d’avant.'));
        }
        header('Location: /admin/knowledge-bases/' . $kbId . '?historique=1');
        exit;
    }

    public function export(string $id, string $file): void
    {
        AuthMiddleware::requirePermission('knowledge_bases.view');
        $user = Session::currentUser();
        $kbId = (int)$id;
        $file = rawurldecode($file);
        $kb   = KnowledgeBase::findById($kbId);
        $store = new KbTableStore();
        $table = $kb !== null && KnowledgeBase::isTable($kb) && WorkTableExport::isFileName($file)
            && (KnowledgeBase::manageableBy($kbId, $user) || KnowledgeBase::visibleBy($kbId, $user))
            ? $store->find($kbId)
            : null;
        if ($table === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo t('Cette table n’existe plus, ou ne vous est pas ouverte.');
            return;
        }
        $locale = I18n::locale();
        $author = (string)Settings::get('app_name', 'MIA');
        session_write_close();

        $generated = WorkTableExport::build($table, $store->rows($kbId), $file, $locale, $author !== '' ? $author : 'MIA');
        header('Content-Type: ' . $generated->mime);
        header('Content-Disposition: ' . FileNaming::contentDisposition($generated->name));
        header('Content-Length: ' . $generated->size());
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $generated->bytes;
    }

    // ===================== Interne =====================

    /**
     * La base est une table que la personne gère — sinon la réponse d'erreur
     * est déjà partie et la table rendue est null. `$write` : le geste écrit
     * (droit « Modifier », jamais la base système).
     *
     * @return array{0: ?array, 1: ?array, 2: KbTableStore}
     */
    private function open(string $id, bool $write): array
    {
        AuthMiddleware::requirePermission($write ? 'knowledge_bases.edit' : 'knowledge_bases.view');
        $kbId  = (int)$id;
        $store = new KbTableStore();
        $user  = Session::currentUser();
        // Lire (page de consultation, export) : toute table que la personne
        // voit ; écrire : celles qu'elle gère.
        $allowed = $write ? KnowledgeBase::manageableBy($kbId, $user) : KnowledgeBase::visibleBy($kbId, $user);
        $kb    = $allowed ? KnowledgeBase::findById($kbId) : null;
        $table = $kb !== null && KnowledgeBase::isTable($kb) ? $store->find($kbId) : null;
        if ($table === null) {
            AjaxResponse::send(['ok' => false, 'error' => t('Table introuvable ou accès refusé.')], 404);
        }
        return [$kb, $table, $store];
    }

    /** La fiche toute rendue, à la page et au filtre demandés (ou imposés par le geste). */
    private function respond(array $kb, array $table, KbTableStore $store, array $extra = [], ?string $q = null, ?int $page = null): void
    {
        $q    = $q ?? (string)($_POST['q'] ?? $_GET['q'] ?? '');
        $page = $page ?? (int)($_POST['page'] ?? $_GET['page'] ?? 1);
        $canEdit = Session::can('knowledge_bases.edit') && KnowledgeBase::manageableBy((int)$kb['id'], Session::currentUser())
            && empty($_POST['readonly']) && empty($_GET['readonly']);
        // Un état passé (volet « Historique ») : la grille d'alors, en lecture
        // seule, les cellules qui diffèrent de maintenant signalées.
        $preview = null;
        $version = (int)($_GET['version'] ?? 0);
        if ($version > 0 && $version !== (int)$table['version']) {
            $entry = $store->entry((int)$kb['id'], $version);
            if ($entry === null || empty($entry['rows_kept'])) {
                AjaxResponse::send(['ok' => false, 'error' => t('Cet état n’est plus conservé : on ne peut plus y revenir.')], 404);
                return;
            }
            $preview = ['entry' => $entry, 'diff' => self::diffWith($store, (int)$kb['id'], $entry, $table)];
            $canEdit = false;
        }
        $view = KbTableView::section($store, $kb, $table, $q, $page, $canEdit, $preview);
        AjaxResponse::send([
            'ok'      => true,
            'html'    => KbTableView::sectionHtml($view),
            'meta'    => $view['meta'],
            'page'    => (int)$view['page']['page'],
        ] + $extra);
    }

    /** La personne peut-elle modifier cette table (revenir à un état, annuler) ? */
    private static function canWrite(int $kbId): bool
    {
        return Session::can('knowledge_bases.edit') && KnowledgeBase::manageableBy($kbId, Session::currentUser());
    }

    /** Ce qui diffère entre un état et la table en service (KbTableHistory::diff). */
    private static function diffWith(KbTableStore $store, int $kbId, array $entry, array $table): array
    {
        $columns = json_decode((string)$entry['columns_json'], true);
        return KbTableHistory::diff(
            is_array($columns) ? WorkTableOps::columns($columns) : [],
            $store->versionRows($kbId, (int)$entry['version']),
            (array)$table['columns'],
            $store->rows($kbId)
        );
    }

    /** Un partiel du volet, rendu en chaîne. */
    private static function render(string $partial, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require ROOT . '/templates/admin/knowledge-bases/partials/' . $partial;
        return (string)ob_get_clean();
    }

    private static function userId(): int
    {
        return (int)(Session::currentUser()['id'] ?? 0);
    }

    private static function label(mixed $raw): string
    {
        $label = is_scalar($raw) ? trim((string)preg_replace('/\s+/u', ' ', (string)$raw)) : '';
        return mb_substr($label, 0, WorkTableOps::LABEL_MAX);
    }

    private static function type(mixed $raw): string
    {
        return in_array($raw, WorkTableOps::TYPES, true) ? (string)$raw : 'text';
    }
}
