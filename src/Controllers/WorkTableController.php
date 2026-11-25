<?php

declare(strict_types=1);

/**
 * Tables de travail d'une conversation, côté écran :
 *
 *   - `GET  /chat/{id}/tables`                       le volet (liste, lignes d'une table, filtre) — tables de la
 *                                                    conversation ET tables permanentes qui y sont engagées
 *                                                    (`table=p12` : celle de la base 12, en lecture seule) ;
 *   - `POST /chat/{id}/tables/{table}/rename`        changer son nom ;
 *   - `POST /chat/{id}/tables/{table}/delete`        la supprimer ;
 *   - `POST /chat/{id}/tables/{table}/keep`          la GARDER : une copie permanente, dans Bases de connaissances ;
 *   - `POST /chat/{id}/tables/{table}/update-source` ajouter à la table permanente d'origine les colonnes
 *                                                    qu'un résultat tiré d'elle apporte (deux temps : `go=1` écrit) ;
 *   - `GET  /chat/{id}/tables/{table}/{fichier}`     la télécharger (Excel ou CSV).
 *
 * Le lien de téléchargement est rendu par l'outil `exporter_table`
 * (WorkTableExport::link) et par le volet. Le fichier est fabriqué ICI, à la
 * demande, depuis les lignes en base : rien n'est stocké en plus de la table,
 * et le fichier reflète son contenu du moment.
 *
 * Deux contrôles, dans cet ordre, sur CHAQUE route : la conversation est à la
 * personne, la table est à cette conversation. Une table d'autrui répond
 * comme une table qui n'existe pas.
 *
 * Contrôleur mince : le métier est dans WorkTableView, WorkTableExport et
 * WorkTableOps. Le volet est renvoyé tout rendu (WorkTableView::panelHtml) :
 * le script de la page n'a aucun libellé.
 */
class WorkTableController
{
    /** Le volet : tables de la conversation et lignes de la table choisie. */
    public function index(string $id): void
    {
        AuthMiddleware::requireAuth();
        $user   = Session::currentUser();
        $convId = (int)$id;
        if (!Conversation::belongsToUser($convId, (int)$user['id'])) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette conversation n’existe plus.')], 404);
            return;
        }
        // Lecture seule : les autres requêtes de l'onglet (une réponse qui
        // s'écrit) n'attendent pas derrière le verrou de session.
        session_write_close();
        // `chip=1` : seulement la puce de la zone de saisie (une table
        // permanente cochée ou décochée, volet fermé) — rien n'est rendu.
        if (isset($_GET['chip'])) {
            AjaxResponse::send(['ok' => true, 'chip' => ChatController::tablesChip(
                count(SqlWorkTableStore::names($convId)) > 0 ? (new SqlWorkTableStore())->all($convId) : [],
                self::engaged($convId)
            )]);
            return;
        }
        // `table` : l'identifiant d'une table de la conversation, ou « p12 »
        // pour la table permanente de la base 12 (WorkTableView::ref).
        $this->respond($convId, WorkTableView::ref($_GET['table'] ?? 0), $_GET['q'] ?? '', (int)($_GET['page'] ?? 1));
    }

    /** Change le nom que lit la personne (le nom court tenu par le modèle ne bouge pas). */
    public function rename(string $id, string $table): void
    {
        [$convId, $found, $store] = $this->owned($id, $table);
        if ($found === null) {
            return;
        }
        try {
            $title = WorkTableView::cleanTitle($_POST['title'] ?? '');
        } catch (\InvalidArgumentException $e) {
            AjaxResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        $store->retitle($convId, (int)$found['id'], $title);
        // `lines` : le nouveau nom suit la table partout où le fil la nomme.
        $this->respond($convId, (int)$found['id'], $_POST['q'] ?? '', (int)($_POST['page'] ?? 1), [
            'lines' => self::lines($convId, $store),
        ]);
    }

    /** Supprime la table et ses lignes — sans retour possible, la page demande confirmation. */
    public function delete(string $id, string $table): void
    {
        [$convId, $found, $store] = $this->owned($id, $table);
        if ($found === null) {
            return;
        }
        // Une opération de fond écrit dans une table de cette conversation :
        // elle s'arrêterait sur une table disparue. On attend sa fin.
        if (self::busy($convId)) {
            AjaxResponse::send(['ok' => false, 'error' => t('Une opération est en cours sur les tables de cette conversation. Attendez sa fin (ou arrêtez-la), puis supprimez la table.')], 409);
            return;
        }
        if (!$store->delete($convId, (string)$found['name'])) {
            AjaxResponse::send(['ok' => false, 'error' => t('La table n’a pas pu être supprimée. Réessayez.')], 500);
            return;
        }
        error_log('[Tables] table « ' . $found['name'] . ' » (#' . (int)$found['id'] . ', ' . (int)$found['rows']
            . ' lignes) supprimée par #' . (int)Session::currentUser()['id'] . ' — conversation #' . $convId);
        // `lines` : le fil ne propose plus de voir une table qui n'existe plus.
        $this->respond($convId, 0, '', 1, ['lines' => self::lines($convId, $store)]);
    }

    /**
     * GARDER une table de travail : une COPIE permanente, rangée dans Bases
     * de connaissances (nature « table »). La table de la conversation reste
     * telle quelle ; les deux vivent ensuite leur vie. Demande le droit de
     * créer une base ; le plafond des tables et leurs limites s'appliquent
     * (KbTableStore::createBase).
     */
    public function keep(string $id, string $table): void
    {
        [$convId, $found, $store] = $this->owned($id, $table);
        if ($found === null) {
            return;
        }
        $user = Session::currentUser();
        if (!Session::can('knowledge_bases.create')) {
            AjaxResponse::send(['ok' => false, 'error' => t('Votre rôle ne vous permet pas de créer une table permanente.')], 403);
            return;
        }
        $conv = Conversation::findById($convId);
        try {
            // Une vue n'a pas de lignes à elle : on garde celles du moment.
            [$found, $rows] = self::tableAndRows($convId, $found, $store);
            $import = KbTableImport::fromWorkTable($found, $rows, Conversation::displayTitle((string)($conv['title'] ?? '')));
            $kbId   = (new KbTableStore())->createBase($user, (string)($_POST['name'] ?? '') !== '' ? (string)$_POST['name'] : WorkTableView::label($found), null, $import);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            AjaxResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        error_log('[Tables] table « ' . $found['name'] . ' » (#' . (int)$found['id'] . ') gardée en table permanente #' . $kbId
            . ' par #' . (int)$user['id'] . ' — conversation #' . $convId);
        $url = '/admin/knowledge-bases/' . $kbId . '/edit';
        // Message rendu ici, lien compris : le script de la page n'a aucun libellé.
        $this->respond($convId, (int)$found['id'], $_POST['q'] ?? '', (int)($_POST['page'] ?? 1), [
            'status_html' => htmlspecialchars(t('Table gardée dans Bases de connaissances.'), ENT_QUOTES, 'UTF-8')
                . (Session::can('knowledge_bases.view')
                    ? ' <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('Ouvrir sa fiche'), ENT_QUOTES, 'UTF-8') . '</a>'
                    : ''),
        ]);
    }

    public function download(string $id, string $table, string $file): void
    {
        AuthMiddleware::requireAuth();
        $user   = Session::currentUser();
        $convId = (int)$id;
        $file   = rawurldecode($file);

        // Table PERMANENTE engagée dans la conversation (« p » + identifiant
        // de sa base) : lien rendu par l'outil exporter_table.
        if (preg_match('/^p(\d+)$/', $table, $m) === 1) {
            $this->downloadPermanent($convId, (int)$m[1], $file, $user);
            return;
        }

        $found = null;
        $rows  = [];
        if (WorkTableExport::isFileName($file) && Conversation::belongsToUser($convId, (int)$user['id'])) {
            $store = new SqlWorkTableStore();
            $found = $store->findById($convId, (int)$table);
            if ($found !== null && WorkTableOps::isHidden((string)$found['name'])) {
                $found = null;
            }
            if ($found !== null) {
                try {
                    [$found, $rows] = self::tableAndRows($convId, $found, $store);
                } catch (\RuntimeException) {
                    $found = null;
                }
            }
        }
        if ($found === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo t('Cette table n’existe plus : elle a été remplacée ou supprimée avec sa conversation. Demandez-la à nouveau.');
            return;
        }

        // Langue et nom de l'application lus AVANT de rendre la session : un
        // classeur de plusieurs milliers de lignes prend quelques secondes, les
        // autres requêtes de l'onglet ne doivent pas attendre derrière son verrou.
        $locale = I18n::locale();
        $author = (string)Settings::get('app_name', 'MIA');
        session_write_close();

        $generated = WorkTableExport::build($found, $rows, $file, $locale, $author !== '' ? $author : 'MIA');

        header('Content-Type: ' . $generated->mime);
        header('Content-Disposition: ' . FileNaming::contentDisposition($generated->name));
        header('Content-Length: ' . $generated->size());
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $generated->bytes;
    }

    /**
     * Téléchargement d'une table permanente depuis une conversation. Trois
     * contrôles : la conversation est à la personne, la base est une table,
     * et elle est ENGAGÉE pour cette conversation — cochée par la personne ou
     * portée par son assistant, avec les mêmes portes que le tour de chat.
     */
    private function downloadPermanent(int $convId, int $kbId, string $file, array $user): void
    {
        $table = null;
        $store = new KbTableStore();
        if (WorkTableExport::isFileName($file) && Conversation::belongsToUser($convId, (int)$user['id'])) {
            $conv = Conversation::findById($convId);
            foreach (KbTableStore::engaged($convId, $user, (int)($conv['profile_id'] ?? 0)) as $engaged) {
                if ((int)$engaged['kb_id'] === $kbId) {
                    $table = $engaged;
                }
            }
        }
        if ($table === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo t('Cette table n’est plus jointe à cette conversation. Cochez-la à nouveau, puis redemandez le fichier.');
            return;
        }
        $rows = $store->rows($kbId);
        // Une vue de la table (filtrée, triée) : calculée maintenant, elle suit la table.
        if (isset($_GET['vue'])) {
            $data = WorkTableView::viewData(['permanent' => true] + $table, WorkTableRecipe::decode($_GET['vue']) ?? [], new ArrayTableRows($rows));
            if ($data['error'] !== '') {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo $data['error'];
                return;
            }
            $table = ['columns' => $data['columns'], 'rows' => count($data['rows'])] + $table;
            $rows  = WorkTableRecipe::strip($data['rows']);
        }
        $locale = I18n::locale();
        $author = (string)Settings::get('app_name', 'MIA');
        session_write_close();

        $generated = WorkTableExport::build($table, $rows, $file, $locale, $author !== '' ? $author : 'MIA');
        header('Content-Type: ' . $generated->mime);
        header('Content-Disposition: ' . FileNaming::contentDisposition($generated->name));
        header('Content-Length: ' . $generated->size());
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $generated->bytes;
    }

    /**
     * La conversation est à la personne, la table à cette conversation —
     * sinon la réponse d'erreur est déjà partie et la table rendue est null.
     *
     * @return array{0:int, 1:?array, 2:SqlWorkTableStore}
     */
    private function owned(string $id, string $table): array
    {
        AuthMiddleware::requireAuth();
        $user   = Session::currentUser();
        $convId = (int)$id;
        $store  = new SqlWorkTableStore();
        $found  = Conversation::belongsToUser($convId, (int)$user['id'])
            ? $store->findById($convId, (int)$table)
            : null;
        // Un brouillon interne (enrichissement, actions sur une table permanente) ne se tient pas d'ici.
        if ($found !== null && WorkTableOps::isHidden((string)$found['name'])) {
            $found = null;
        }
        if ($found === null) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette table n’existe plus.')], 404);
        }
        return [$convId, $found, $store];
    }

    /**
     * Le volet tout rendu, avec ce qu'il faut pour tenir la page à jour (puce,
     * table choisie). Les tables permanentes ENGAGÉES dans la conversation y
     * figurent, en lecture seule (KbTableStore::engaged : mêmes portes que le
     * tour de chat).
     */
    private function respond(int $convId, int|string $tableRef, mixed $q, int $page, array $extra = []): void
    {
        $view = WorkTableView::panel(
            new SqlWorkTableStore(),
            $convId,
            $tableRef,
            $q,
            $page,
            self::busy($convId),
            Session::can('knowledge_bases.create'),
            $engaged = self::engaged($convId),
            new KbTableStore(),
            Session::can('knowledge_bases.view'),
            static fn(int $kbId): bool => self::canWriteKb($kbId)
        );
        AjaxResponse::send([
            'ok'       => true,
            'chip'     => ChatController::tablesChip((new SqlWorkTableStore())->all($convId), $engaged),
            'selected' => $view['selected']['id'] ?? 0,
            'html'     => WorkTableView::panelHtml($view),
        ] + $extra);
    }

    /**
     * Ajouter à la table permanente d'origine ce qu'un résultat tiré d'elle
     * apporte : les colonnes qu'elle n'a pas, rapprochées ligne à ligne par
     * une colonne commune qui identifie une ligne (WorkTableView::matchKey).
     * Deux temps : sans `go`, la phrase à confirmer ; avec `go=1`, l'écriture
     * — un nouvel état de l'historique de la table (retour possible).
     * C'est un geste de la PERSONNE : il demande le droit de modifier la
     * table, pas le réglage « MIA peut ».
     */
    public function updateSource(string $id, string $table): void
    {
        [$convId, $found, $store] = $this->owned($id, $table);
        if ($found === null) {
            return;
        }
        $user = Session::currentUser();
        $kbId = (int)(WorkTableView::basesOf($found)[0]['kb'] ?? 0);
        if ($kbId <= 0 || WorkTableView::isView($found)) {
            AjaxResponse::send(['ok' => false, 'error' => t('Ce résultat ne vient d’aucune table permanente.')], 422);
            return;
        }
        if (!self::canWriteKb($kbId)) {
            AjaxResponse::send(['ok' => false, 'error' => t('Vous ne pouvez pas modifier cette table.')], 403);
            return;
        }
        $kbStore = new KbTableStore();
        $perm    = $kbStore->find($kbId);
        if ($perm === null) {
            AjaxResponse::send(['ok' => false, 'error' => t('Cette table n’existe plus.')], 404);
            return;
        }
        $permRows = $kbStore->rows($kbId);
        $resRows  = $store->rows((int)$found['id']);
        $missing  = WorkTableView::missingColumns((array)$perm['columns'], (array)$found['columns']);
        $key      = WorkTableView::matchKey((array)$perm['columns'], $permRows, (array)$found['columns'], $resRows);
        if ($missing === []) {
            AjaxResponse::send(['ok' => false, 'error' => t('« {table} » a déjà toutes les colonnes de ce résultat : rien à ajouter.', ['table' => (string)$perm['title']])], 422);
            return;
        }
        if ($key === null) {
            AjaxResponse::send(['ok' => false, 'error' => t('Impossible de rapprocher les lignes : aucune colonne commune n’identifie une ligne de « {table} ». Demandez à MIA de reporter ce résultat.', ['table' => (string)$perm['title']])], 422);
            return;
        }
        $newKeys = [];
        foreach (WorkTableView::visibleColumns((array)$found['columns']) as $c) {
            if (in_array((string)$c['label'], $missing, true)) {
                $newKeys[] = (string)$c['key'];
            }
        }
        try {
            $spec = KbTableWrite::spec((array)$perm['columns'], (array)$found['columns'], [
                'mode' => KbTableWrite::MODE_UPDATE, 'cle' => $key[0], 'cle_source' => $key[1], 'colonnes' => $newKeys,
            ]);
            $plan = KbTableWrite::plan((array)$perm['columns'], $permRows, (array)$found['columns'], $resRows, $spec);
        } catch (KbTableTooLargeException | \InvalidArgumentException $e) {
            AjaxResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        $stats  = $plan['stats'];
        $keyLbl = '';
        foreach ((array)$perm['columns'] as $c) {
            if ((string)$c['key'] === $key[0]) {
                $keyLbl = (string)$c['label'];
            }
        }
        if (empty($_POST['go'])) {
            AjaxResponse::send(['ok' => true, 'confirm' => t('Ajouter {colonnes} à « {table} » : {lignes} rapprochées par « {cle} », {ignorees} ignorées. Le changement s’inscrit dans l’historique de la table : on peut revenir en arrière.', [
                'colonnes' => I18n::listeCitee($missing),
                'table'    => (string)$perm['title'],
                'lignes'   => WorkTableView::rowsLabel((int)$stats['touched']),
                'cle'      => $keyLbl,
                'ignorees' => I18n::plural((int)$stats['unmatched'], '{n} ligne du résultat', '{n} lignes du résultat'),
            ])]);
            return;
        }
        $written = $kbStore->replace($kbId, $plan['columns'], $plan['rows'], null, (int)$user['id'],
            KbTableHistory::change(KbTableHistory::KIND_MIA, [
                'mode'          => KbTableWrite::MODE_UPDATE,
                'touched'       => (int)$stats['touched'],
                'added'         => 0,
                'deleted'       => 0,
                'after'         => (int)$stats['after'],
                'columns_added' => array_values(array_map('strval', (array)$stats['columns_added'])),
                'conversation'  => $convId,
                'title'         => mb_substr((string)(Conversation::findById($convId)['title'] ?? ''), 0, 120),
            ]),
            KbTableStore::stamp($perm));
        if ($written === null || !empty($written['stale'])) {
            AjaxResponse::send(['ok' => false, 'error' => t('La table vient d’être modifiée par quelqu’un d’autre. Réessayez.')], 409);
            return;
        }
        error_log('[Tables] résultat « ' . $found['name'] . ' » reporté dans la table permanente #' . $kbId . ' par #' . (int)$user['id'] . ' — conversation #' . $convId);
        $url = '/admin/knowledge-bases/' . $kbId . '?historique=1';
        $this->respond($convId, 'p' . $kbId, '', 1, [
            'status_html' => htmlspecialchars(t('« {table} » complétée.', ['table' => (string)$perm['title']]), ENT_QUOTES, 'UTF-8')
                . (Session::can('knowledge_bases.view')
                    ? ' <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('Voir son historique'), ENT_QUOTES, 'UTF-8') . '</a>'
                    : ''),
        ]);
    }

    /** La personne peut-elle modifier la table permanente de cette base ? (droit et base qu'elle gère) */
    private static function canWriteKb(int $kbId): bool
    {
        $user = Session::currentUser();
        return $user !== null && Session::can('knowledge_bases.edit') && KnowledgeBase::manageableBy($kbId, $user);
    }

    /**
     * Une table de la conversation et ses lignes — celles d'une VUE sont
     * recalculées sur sa table permanente (qui doit être encore engagée).
     *
     * @return array{0: array, 1: list<array<string,string>>}
     * @throws \RuntimeException la vue ne se lit plus
     */
    private static function tableAndRows(int $convId, array $table, WorkTableStore $store): array
    {
        if (!WorkTableView::isView($table)) {
            return [$table, $store->rows((int)$table['id'])];
        }
        $kb = WorkTableView::viewKb($table);
        foreach (self::engaged($convId) as $perm) {
            if ((int)$perm['kb_id'] === $kb) {
                $data = WorkTableView::viewData(['permanent' => true] + $perm, WorkTableView::viewSteps($table), new KbTableStore());
                if ($data['error'] !== '') {
                    throw new \RuntimeException($data['error']);
                }
                return [['columns' => $data['columns'], 'rows' => count($data['rows'])] + $table, WorkTableRecipe::strip($data['rows'])];
            }
        }
        throw new \RuntimeException(t('La table de cette vue n’est plus jointe à la conversation.'));
    }

    /** Tables permanentes engagées dans la conversation, pour la personne connectée. @return list<array> */
    private static function engaged(int $convId): array
    {
        $conv = Conversation::findById($convId);
        return KbTableStore::engaged($convId, Session::currentUser(), (int)($conv['profile_id'] ?? 0));
    }

    /**
     * La ligne « Voir » de chaque réponse qui nomme des tables, recalculée :
     * après un renommage ou une suppression, la page remplace celles qu'elle
     * affiche (une réponse absente de la carte n'en a plus).
     *
     * @return array<string, string> identifiant de message → HTML de sa ligne
     */
    private static function lines(int $convId, WorkTableStore $store): array
    {
        $out = [];
        foreach (WorkTableView::forMessages(Message::withTableRefs($convId), $store->all($convId), self::engaged($convId), new KbTableStore()) as $msgId => $tables) {
            $out[(string)$msgId] = WorkTableView::lineHtml($tables);
        }
        return $out;
    }

    /** Une opération de fond (enrichissement, action par ligne) tourne-t-elle sur cette conversation ? */
    private static function busy(int $convId): bool
    {
        try {
            return JobQueue::activeByDedupKey('table_mission:' . $convId) !== null;
        } catch (\Throwable $e) {
            error_log('[Tables] file des opérations illisible : ' . $e->getMessage());
            return false;
        }
    }
}
