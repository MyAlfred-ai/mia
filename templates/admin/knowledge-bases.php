<?php
/**
 * Espaces → Bases de connaissances (/admin/knowledge-bases) : la liste.
 *
 * Cliquer une base — hors de ses commandes — affiche sa COMPOSITION à droite :
 * ce qui l'alimente (fichiers, dossier synchronisé, cerveau MyBrain), comment
 * elle est cherchée, les assistants qu'elle sert et ceux avec qui elle est
 * partagée. Le schéma des assistants et des tâches programmées, dessiné par
 * diagram.js + kb-diagram.js depuis $diagram (KbDiagram : catalogue +
 * configuration de chaque base). Sur un écran étroit, il se déplie sous la
 * base cliquée. Créer et modifier une base se fait sur sa fiche, en page
 * pleine (knowledge-bases/edit.php) ; sans JavaScript, le nom y mène.
 *
 * Chaque carte porte son état VIVANT (partiel kb-status.php), rafraîchi sans
 * recharger par GET /admin/knowledge-bases/status.
 */
$diagramJsVersion   = @filemtime(ROOT . '/public/assets/js/diagram.js') ?: 1;
$kbDiagramJsVersion = @filemtime(ROOT . '/public/assets/js/kb-diagram.js') ?: 1;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Bases de connaissances') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kb-content">
    <h1><?= te('Bases de connaissances') ?></h1>
    <p class="kb-lede">
        <?= teb('Des collections de documents que vos assistants consultent pour répondre : à chaque question, les extraits les plus pertinents rejoignent le prompt. Une base peut aussi être un {b}cerveau MyBrain{/b}, interrogé en direct.') ?>
    </p>

    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($qdrantUp)): ?>
        <div class="alert alert-error" style="margin-bottom:16px">
            ⚠ <?= te('Le moteur vectoriel (Qdrant) est indisponible : les recherches passent en mode dégradé (recherche texte) et les nouveaux documents ne seront vectorisés qu\'après son retour (« Réindexer », dans la section « Avancé » de chaque base).') ?>
            <?php if (!empty($canSeeVector)): ?>
                <a href="/admin/knowledge-bases/vector"><?= te('Voir le moteur vectoriel') ?></a>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($autoSyncPaused)): ?>
        <!-- Budget IA atteint (KbSync::maybeRunDue) : sans montant, les créateurs voient cette page. -->
        <div class="llm-setup-banner" role="status">
            <div class="llm-setup-banner-text">
                <div class="llm-setup-banner-title"><?= te('Synchronisations automatiques suspendues jusqu\'au {date}', ['date' => (string)$budgetResetLabel]) ?></div>
                <div class="llm-setup-banner-sub"><?= te('Le budget IA du mois est atteint. « Synchroniser maintenant » reste disponible, et son coût est compté.') ?></div>
            </div>
            <?php if (!empty($canSeeBudget)): ?>
                <div class="llm-setup-banner-actions"><a href="/admin/budget" class="btn-secondary"><?= te('Voir le budget') ?></a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($embeddingProviders)): ?>
        <div class="card" style="margin-bottom:24px">
            <h2 style="margin:0 0 8px;font-size:16px"><?= te('Configuration requise') ?></h2>
            <p style="margin:0;font-size:13px;color:var(--text-muted)">
                <?php /* Phrase entière dans le dictionnaire : le lien {lien} est posé
                         APRÈS l'échappement, comme les balises de teb(). */ ?>
                <?= str_replace(
                    '{lien}',
                    '<a href="/admin/llms">' . te('Gestion des LLMs') . '</a>',
                    teb('La vectorisation nécessite une clé API {b}OpenAI{/b}, {b}Mistral{/b} ou {b}Gemini{/b}. Configurez-en une dans {lien} pour créer une base de connaissances.')
                ) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php
        // Sources (nom, image, ce que c'est) : la table de KbDiagram, partagée
        // avec la fiche et le schéma — filtres de la barre d'outils ET
        // pastille de chaque carte en dépendent. `label` et `what` y sont la
        // clé française : on affiche ceux des accesseurs traduits
        // (KbDiagram::sourceLabel / sourceWhat), la table ne sert qu'aux logos.
        $syncProviderMeta = KbDiagram::SOURCES;

        // Icône de la source « fichiers déposés » (flèche montante).
        $uploadIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';

        /** Clé de source d'une base : `mybrain` (en direct), le fournisseur synchronisé, sinon `upload`. */
        $kbSourceKey = static function (array $kb): string {
            if (KnowledgeBase::isRemote($kb)) {
                return 'mybrain';
            }
            return (!empty($kb['sync_enabled']) && !empty($kb['sync_provider']))
                ? (string)$kb['sync_provider']
                : 'upload';
        };

        // Un filtre n'est proposé que s'il a de quoi filtrer : une puce
        // « SharePoint » sur une instance sans SharePoint n'est qu'un cul-de-sac.
        $srcCounts = [];
        foreach ($kbs as $kbRow) {
            $k = $kbSourceKey($kbRow);
            $srcCounts[$k] = ($srcCounts[$k] ?? 0) + 1;
        }
        $srcOrder = array_merge(['upload'], array_keys($syncProviderMeta));

        // Tris proposés. `name` est le défaut (= l'ordre SQL des bases), les
        // autres sont appliqués côté client sur les attributs des cartes.
        $kbSorts = [
            'name'       => t('Nom (A → Z)'),
            'usage'      => t('Utilisation'),
            'recent'     => t('Utilisée récemment'),
            'size'       => t('Taille'),
            'docs'       => t('Documents'),
            'assistants' => t('Assistants liés'),
            'created'    => t('Ajoutée récemment'),
        ];

        $totalBytes = 0;
        $totalDocs  = 0;
        foreach ($kbs as $kbRow) {
            $totalBytes += (int)($kbRow['total_size'] ?? 0);
            $totalDocs  += (int)($kbRow['doc_count'] ?? 0);
        }

        // Une base de documents demande une clé d'embedding ; un administrateur
        // peut toujours brancher un cerveau MyBrain.
        $canCreate = $isAdminManager || (!empty($embeddingProviders) && ($kbRemaining ?? 0) > 0);
        // Droit « Bases de connaissances — Créer » absent (Personnes → Rôles) : pas de bouton.
        $kbCreateOffered = !empty($kbCanCreate);
        $cannotCreateWhy = empty($embeddingProviders) ? t('Configurez une clé API d\'embedding') : t('Quota de bases atteint');

        $kbCardIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>';
        $icoAssistant = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/></svg>';
        $icoShare     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        $icoPrivate   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    ?>

    <!-- Bases — et, à droite, la composition de la base cliquée -->
    <div class="pf-split kb-split" id="kbSplit">
    <div class="card pf-list-card kb-list-card">
        <div class="kb-listbar">
            <div class="kb-listbar-head">
                <div class="kb-listbar-heading">
                    <h2 class="kb-listbar-title"><?= te('Vos bases') ?></h2>
                    <div class="kb-total">
                        <span class="kb-total-label"><?= htmlspecialchars(I18n::plural(count($kbs), '{n} base', '{n} bases')) ?> · <?= htmlspecialchars(I18n::plural($totalDocs, '{n} document', '{n} documents')) ?> · <?= Format::sizeMo($totalBytes) ?></span>
                        <?php require ROOT . '/templates/partials/kb-status-overview.php'; ?>
                    </div>
                </div>
                <div class="kb-listbar-actions">
                    <?php if (!$isAdminManager): ?>
                        <span class="badge <?= ($kbRemaining ?? 0) > 0 ? 'badge-gray' : 'badge-red' ?>" title="<?= te('Quota de bases de connaissances') ?>">
                            <?= (int)$kbCount ?> / <?= (int)$kbMax ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!$kbCreateOffered): ?>
                    <?php elseif ($canCreate): ?>
                        <a href="/admin/knowledge-bases/new" class="btn-primary" id="btnNewKb"><?= te('+ Nouvelle base') ?></a>
                    <?php else: ?>
                        <button type="button" class="btn-primary" id="btnNewKb" disabled title="<?= htmlspecialchars($cannotCreateWhy) ?>"><?= te('+ Nouvelle base') ?></button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (count($kbs) > 1): ?>
                <div class="kb-listbar-tools">
                    <div class="kb-srcfilter" role="group" aria-label="<?= te('Filtrer par source d\'alimentation') ?>">
                        <button type="button" class="kb-srcbtn is-on" data-src="all" aria-pressed="true">
                            <?= te('Toutes') ?> <span class="kb-srcbtn-n"><?= count($kbs) ?></span>
                        </button>
                        <?php foreach ($srcOrder as $srcKey): ?>
                            <?php if (empty($srcCounts[$srcKey])) continue; ?>
                            <?php
                                $srcLabel = $srcKey === 'upload'
                                    ? t('Fichiers déposés')
                                    : KbDiagram::sourceLabel($srcKey);
                                $srcTitle = match ($srcKey) {
                                    'upload'  => t('Bases alimentées par des fichiers déposés à la main'),
                                    'mybrain' => t('Bases en direct : {source}', ['source' => KbDiagram::sourceWhat('mybrain')]),
                                    default   => t('Bases synchronisées depuis {source}', [
                                        'source' => KbDiagram::sourceWhat($srcKey) ?: t('une source distante'),
                                    ]),
                                };
                            ?>
                            <button type="button" class="kb-srcbtn" data-src="<?= htmlspecialchars($srcKey) ?>"
                                    aria-pressed="false" title="<?= htmlspecialchars($srcTitle) ?>">
                                <?php if ($srcKey === 'upload'): ?>
                                    <span class="kb-srcbtn-ico"><?= $uploadIcon ?></span>
                                <?php else: ?>
                                    <img class="kb-srcbtn-ico" src="<?= htmlspecialchars($syncProviderMeta[$srcKey]['logo']) ?>" alt="">
                                <?php endif; ?>
                                <?= htmlspecialchars($srcLabel) ?> <span class="kb-srcbtn-n"><?= (int)$srcCounts[$srcKey] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="kb-listbar-right">
                        <input type="search" id="kbFilter" class="form-input kb-filter"
                               placeholder="<?= te('Filtrer (nom, assistant, personne…)') ?>" autocomplete="off" aria-label="<?= te('Filtrer les bases') ?>">
                        <label class="kb-sortbox">
                            <span class="kb-sortbox-label"><?= te('Trier par') ?></span>
                            <select id="kbSort" class="form-select">
                                <?php foreach ($kbSorts as $sortKey => $sortLabel): ?>
                                    <option value="<?= htmlspecialchars($sortKey) ?>"><?= htmlspecialchars($sortLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($kbs)): ?>
            <div class="kb-empty">
                <span class="kb-empty-ico" aria-hidden="true"><?= $kbCardIcon ?></span>
                <strong><?= te('Aucune base pour l\'instant.') ?></strong>
                <span><?= te('Créez la première avec « + Nouvelle base », déposez-y vos documents, puis rattachez-la à vos assistants.') ?></span>
            </div>
        <?php endif; ?>

        <?php
            // Index de recherche du filtre : noms des assistants et des
            // bénéficiaires du partage (ceux que le gestionnaire voit).
            $profileNameById = [];
            foreach ($manageableProfiles as $mp) { $profileNameById[(int)$mp['id']] = (string)$mp['name']; }
            $userNameById = [];
            foreach ($shareableUsers as $su) { $userNameById[(int)$su['id']] = (string)$su['name']; }
            $groupNameById = [];
            foreach ($shareableGroups as $sg) { $groupNameById[(int)$sg['id']] = (string)$sg['name']; }
        ?>
        <div class="kb-grid">
            <?php foreach ($kbs as $kb): ?>
            <?php
                $kbId        = (int)$kb['id'];
                $docs        = $docsByKb[$kbId] ?? [];
                $linkedIds   = $linkedProfileIdsByKb[$kbId] ?? [];
                $linkedTotal = count($linkedIds);
                $docCount    = (int)($kb['doc_count'] ?? count($docs));

                // Droits : propriétaire (créateur/admin) = tout ; partage en
                // écriture = la fiche sans partage ni suppression ; partage
                // simple = lecture et rattachement (pas de fiche).
                $isOwnedKb   = (bool)($ownedByKb[$kbId] ?? false);
                $isSharedKb  = (int)($kb['is_shared'] ?? 0) === 1;
                $canManageKb = $isOwnedKb || (int)($kb['share_can_manage'] ?? 0) === 1;
                $isRemoteKb  = KnowledgeBase::isRemote($kb);
                $isSystemKb  = (int)($kb['is_system'] ?? 0) === 1;
                $editUrl     = '/admin/knowledge-bases/' . $kbId . '/edit';

                $syncActive = !empty($kb['sync_enabled']) && !empty($kb['sync_provider']);
                $syncMeta   = $syncProviderMeta[(string)($kb['sync_provider'] ?? '')]
                    ?? ['label' => (string)($kb['sync_provider'] ?? ''), 'logo' => '', 'what' => 'une source distante'];
                // Affichés traduits (la table porte la clé française).
                $syncLabel  = KbDiagram::sourceLabel((string)($kb['sync_provider'] ?? ''));
                $syncWhat   = KbDiagram::sourceWhat((string)($kb['sync_provider'] ?? '')) ?: t('une source distante');

                // Qui la voit, en un mot (le schéma détaille).
                $shares     = $isOwnedKb ? ($sharesByKb[$kbId] ?? ['users' => [], 'groups' => []]) : ['users' => [], 'groups' => []];
                $shareCount = count($shares['users']) + count($shares['groups']);
                $isPrivateKb = false;
                if ($isSystemKb) {
                    $accessLabel = t('Tout le monde');
                } elseif (!$isOwnedKb) {
                    $accessLabel = t('Partagée avec vous');
                } elseif ($shareCount > 0) {
                    $accessLabel = t('Partagée · {n}', ['n' => $shareCount]);
                } else {
                    $accessLabel = t('Privée');
                    $isPrivateKb = true;
                }

                // Index de recherche du filtre client : nom, description,
                // créateur, assistants liés, bénéficiaires du partage, source
                // — mots de la source dans la langue de l'écran (on cherche ce
                // qu'on lit).
                $searchIndex = mb_strtolower(implode(' ', array_merge(
                    [(string)$kb['name'], (string)($kb['description'] ?? ''), (string)($kb['creator_name'] ?? '')],
                    array_values(array_intersect_key($profileNameById, array_flip($linkedIds))),
                    array_values(array_intersect_key($userNameById, $shares['users'])),
                    array_values(array_intersect_key($groupNameById, $shares['groups'])),
                    [$isRemoteKb ? t('mybrain cerveau en direct') : ($syncActive ? mb_strtolower($syncLabel) : t('fichiers deposes upload'))]
                )));

                // Clés de tri (barre d'outils) : en attributs, triées sur les
                // nœuds déjà rendus, sans dupliquer les données de la carte.
                $srcKeyCard  = $kbSourceKey($kb);
                $usageCount  = (int)($kb['query_count'] ?? 0);
                $usageLastAt = (string)($kb['last_used_at'] ?? '');

                // État vivant (KbStatus) : rafraîchi sans rechargement par
                // GET /admin/knowledge-bases/status, qui renvoie le même bloc.
                $kbStatus = $kbStatuses[$kbId] ?? KbStatus::summarize($kb, KbStatus::countDocuments($docs), []);
            ?>
            <article class="kb-card<?= empty($kb['is_active']) ? ' kb-card-off' : '' ?><?= $isSystemKb ? ' kb-card-sys' : '' ?>"
                     id="base-<?= $kbId ?>"
                     data-kb-id="<?= $kbId ?>"
                     data-edit-url="<?= $canManageKb ? htmlspecialchars($editUrl) : '' ?>"
                     data-tone="<?= htmlspecialchars((string)$kbStatus['tone'], ENT_QUOTES) ?>"
                     data-state="<?= htmlspecialchars((string)$kbStatus['state'], ENT_QUOTES) ?>"
                     data-search="<?= htmlspecialchars($searchIndex, ENT_QUOTES) ?>"
                     data-src="<?= htmlspecialchars($srcKeyCard, ENT_QUOTES) ?>"
                     data-name="<?= htmlspecialchars(mb_strtolower((string)$kb['name']), ENT_QUOTES) ?>"
                     data-size="<?= (int)($kb['total_size'] ?? 0) ?>"
                     data-docs="<?= $docCount ?>"
                     data-usage="<?= $usageCount ?>"
                     data-lastused="<?= htmlspecialchars($usageLastAt, ENT_QUOTES) ?>"
                     data-assistants="<?= $linkedTotal ?>"
                     data-created="<?= htmlspecialchars((string)($kb['created_at'] ?? ''), ENT_QUOTES) ?>">
                <div class="kb-card-head">
                    <div class="kb-card-icon"><?= $kbCardIcon ?></div>
                    <div class="kb-card-headmain">
                        <?php /* Lien vers la fiche sans JavaScript ; avec, il montre la
                                 composition (comme le reste de la carte) — le clavier y accède. */ ?>
                        <a class="kb-card-title kb-name-link" href="<?= htmlspecialchars($canManageKb ? $editUrl : '/admin/knowledge-bases#base-' . $kbId) ?>"><?= htmlspecialchars($kb['name']) ?></a>
                        <div class="kb-card-pills">
                            <?php if ($isSystemKb): ?>
                                <span class="kb-pill kb-pill-sys" title="<?= te('Base livrée avec MIA : son contenu est resynchronisé depuis sa documentation à chaque déploiement. Non modifiable à la main.') ?>"><?= te('Système') ?></span>
                            <?php endif; ?>
                            <?php if ($isSharedKb): ?>
                                <span class="kb-pill kb-pill-shared" title="<?= $canManageKb
                                    ? te('Base d\'un autre gestionnaire, partagée avec vous en modification : vous gérez ses documents, sans pouvoir la supprimer ni la repartager.')
                                    : te('Base d\'un autre gestionnaire, partagée avec vous : vous pouvez la rattacher à vos assistants, sans modifier son contenu.') ?>">
                                    <?= $canManageKb ? te('Partagée avec vous') : te('Partagée avec vous · lecture') ?>
                                </span>
                            <?php endif; ?>
                            <?php if (empty($kb['is_active'])): ?>
                                <span class="kb-pill kb-pill-inactive" title="<?= te('Base désactivée : ignorée par les assistants') ?>"><?= te('Inactive') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isRemoteKb): ?>
                        <span class="kb-card-src"
                              title="<?= te('Base en direct : {source} à chaque message — rien n\'est copié dans MIA.', ['source' => KbDiagram::sourceWhat('mybrain')]) ?>">
                            <img src="<?= htmlspecialchars($syncProviderMeta['mybrain']['logo']) ?>" alt="MyBrain">
                        </span>
                    <?php elseif ($syncActive): ?>
                        <?php /* La pastille de coin suit `data-tone` de la carte (CSS) :
                                 rafraîchie avec l'état, sans re-rendre l'en-tête. */ ?>
                        <span class="kb-card-src"
                              title="<?= !empty($kb['sync_folder_name'])
                                  ? te('Base alimentée par {source} (« {dossier} »)', ['source' => $syncWhat, 'dossier' => (string)$kb['sync_folder_name']])
                                  : te('Base alimentée par {source}', ['source' => $syncWhat]) ?>">
                            <img src="<?= htmlspecialchars($syncMeta['logo']) ?>" alt="<?= htmlspecialchars($syncLabel) ?>">
                        </span>
                    <?php else: ?>
                        <span class="kb-card-src" title="<?= te('Base alimentée par des fichiers déposés à la main') ?>">
                            <?= $uploadIcon ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($kb['description'])): ?>
                    <div class="kb-card-desc" title="<?= htmlspecialchars($kb['description']) ?>"><?= htmlspecialchars($kb['description']) ?></div>
                <?php endif; ?>

                <?php
                    // Zone vivante : état (en cours, en attente, à jour, en
                    // échec…), barre, légende et volumétrie. Partiel partagé
                    // avec le rafraîchissement en direct ; portée isolée, ses
                    // variables ne débordent pas sur celles de la boucle.
                    (static function (array $kb, array $kbStatus, bool $kbCanManage): void {
                        $kbStatusVariant = 'card';
                        require ROOT . '/templates/partials/kb-status.php';
                    })($kb, $kbStatus, $canManageKb);
                ?>

                <div class="kb-card-foot">
                    <span class="kb-card-fact" title="<?= te('Assistants qui interrogent cette base') ?>">
                        <?= $icoAssistant ?><?= $linkedTotal === 0 ? te('Aucun assistant') : htmlspecialchars(I18n::plural($linkedTotal, '{n} assistant', '{n} assistants')) ?>
                    </span>
                    <span class="kb-card-fact" title="<?= te('Qui voit cette base') ?>">
                        <?= $isPrivateKb ? $icoPrivate : $icoShare ?><?= htmlspecialchars($accessLabel) ?>
                    </span>
                    <?php if ($canManageKb): ?>
                        <a class="btn-secondary kb-card-edit" href="<?= htmlspecialchars($editUrl) ?>" title="<?= te('Ouvrir la fiche de la base') ?>"><?= $isSystemKb ? te('Ouvrir') : te('Modifier') ?></a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($kbs)): ?>
            <div class="kb-nomatch" id="kbNoMatch" hidden>
                <?= te('Aucune base ne correspond à ce filtre.') ?>
                <button type="button" class="kb-nomatch-reset" id="kbResetFilters"><?= te('Tout afficher') ?></button>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($diagram !== null): ?>
    <?php /* Composition de la base cliquée : remplie par le script de la page
             (rien sans JavaScript — la fiche s'ouvre alors par le nom). */ ?>
    <aside class="sched-diagram-panel pf-diagram-panel" id="kbDiagramPanel" aria-labelledby="kbDiagramTitle" hidden>
        <div class="sched-diagram-head">
            <div class="sched-diagram-head-text">
                <span class="sched-diagram-kicker"><?= te('Composition de la base') ?></span>
                <h2 id="kbDiagramTitle"></h2>
            </div>
            <a class="btn-secondary sched-diagram-edit" id="kbDiagramEdit" href="/admin/knowledge-bases"><?= te('Modifier') ?></a>
            <button type="button" class="sched-diagram-close" id="kbDiagramClose" aria-label="<?= te('Fermer le schéma') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="sched-diagram" id="kbDiagram"></div>
    </aside>
    <?php endif; ?>
    </div>
</div>

<?php if ($diagram !== null): ?>
<script>
// Composition des bases (clic sur une carte) : catalogue et configurations
// calculés par le serveur (KbDiagram), dessinés par diagram.js +
// kb-diagram.js — chargés avant le script de la page.
window.KB_DIAGRAMS = <?= json_encode($diagram, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= (int)$diagramJsVersion ?>"></script>
<script src="/assets/js/kb-diagram.js?v=<?= (int)$kbDiagramJsVersion ?>"></script>
<?php endif; ?>
<script>
(function () {
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    // ── Composition de la base cliquée ──
    // Même geste que la liste des assistants : un clic sur la carte — hors de
    // ses commandes (Modifier, synchroniser) — montre le schéma de la base : à
    // droite sur un écran large (la base de l'adresse #base-ID, sinon la
    // première, affichée d'office), déplié sous la carte sur un écran étroit,
    // où un second clic le replie. La configuration de chaque base est déjà
    // dans la page : aucun aller-retour au clic.
    const listDiagram = (function setupListDiagram() {
        const conf  = window.KB_DIAGRAMS;
        const split = document.getElementById('kbSplit');
        const panel = document.getElementById('kbDiagramPanel');
        const host  = document.getElementById('kbDiagram');
        if (!conf || !split || !panel || !host || !window.MiaKbDiagram) return null;

        const title    = document.getElementById('kbDiagramTitle');
        const edit     = document.getElementById('kbDiagramEdit');
        const closeBtn = document.getElementById('kbDiagramClose');
        // Même seuil que la mise en page côte à côte de main.css (.pf-split).
        const wide = window.matchMedia('(min-width: 1200px)');
        let current = 0;

        const specOf = (id) => (conf.bases && conf.bases[String(id)]) || null;
        const cardOf = (id) => document.getElementById('base-' + id);
        // Une carte masquée par les filtres ne se montre pas.
        const shown = (card) => !!card && !card.hidden;
        const firstShown = () => Array.from(document.querySelectorAll('.kb-card')).find(shown) || null;

        // Côte à côte : le panneau suit la liste ; en colonne : sous la carte.
        function place(card) {
            if (wide.matches) {
                if (panel.parentElement !== split) split.appendChild(panel);
            } else if (card && card.nextElementSibling !== panel) {
                card.after(panel);
            }
        }

        function paint(animate) {
            const spec = specOf(current);
            const card = cardOf(current);
            if (!spec || !card) return;
            if (title) title.textContent = spec.name || '';
            if (edit) {
                const url = card.dataset.editUrl || '';
                edit.hidden = url === '';
                edit.href = url || '/admin/knowledge-bases';
                edit.textContent = spec.system ? <?= tj('Ouvrir') ?> : <?= tj('Modifier') ?>;
            }
            window.MiaKbDiagram.mount(host, spec, conf.catalog, { animate });
        }

        function select(id, opts) {
            const card = cardOf(id);
            if (!specOf(id) || !card) return;
            current = Number(id);
            document.querySelectorAll('.kb-card').forEach((el) => {
                const on = el === card;
                el.classList.toggle('is-selected', on);
                if (on) el.setAttribute('aria-current', 'true');
                else el.removeAttribute('aria-current');
            });
            place(card);
            panel.hidden = false;
            split.classList.add('is-open');
            paint(opts.animate !== false);
            if (opts.remember) {
                try { history.replaceState(null, '', '#base-' + current); } catch (_e) { /* adresse inchangée */ }
            }
            if (!wide.matches && opts.scroll) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        function close(opts) {
            const card = cardOf(current);
            current = 0;
            panel.hidden = true;
            split.classList.remove('is-open');
            document.querySelectorAll('.kb-card.is-selected').forEach((el) => {
                el.classList.remove('is-selected');
                el.removeAttribute('aria-current');
            });
            try { history.replaceState(null, '', location.pathname + location.search); } catch (_e) { /* adresse inchangée */ }
            if (!opts || opts.focus !== false) card?.querySelector('.kb-name-link')?.focus();
        }

        // Un clic sur la carte — hors de ses commandes — montre son schéma. Le
        // nom, lien vers la fiche sans JavaScript, le montre aussi.
        document.querySelectorAll('.kb-card').forEach((card) => {
            card.classList.add('kb-card-clickable');
            card.addEventListener('click', (e) => {
                const name = e.target.closest('.kb-name-link');
                if (!name && e.target.closest('button, a, form, label, input, select, textarea, summary, details')) return;
                if (name) e.preventDefault();
                const id = Number(card.dataset.kbId);
                if (!wide.matches && id === current && !panel.hidden) {
                    close();
                    return;
                }
                select(id, { remember: true, scroll: true });
            });
        });
        closeBtn?.addEventListener('click', () => close());

        wide.addEventListener('change', () => {
            if (current) {
                place(cardOf(current));
                paint(false);
            } else if (wide.matches) {
                const first = firstShown();
                if (first) select(first.dataset.kbId, { animate: true });
            }
        });

        const fromHash = /^#base-(\d+)$/.exec(location.hash || '');
        if (fromHash && specOf(fromHash[1])) {
            select(fromHash[1], { animate: true, scroll: true });
            cardOf(fromHash[1])?.scrollIntoView({ block: 'nearest' });
        } else if (wide.matches) {
            const first = firstShown();
            if (first) select(first.dataset.kbId, { animate: true });
        }

        return {
            /**
             * Après un filtre ou un tri : le panneau suit sa carte ; une carte
             * masquée cède la place à la première visible (écran large) ou
             * replie le schéma (écran étroit, il vivait sous elle).
             */
            refresh() {
                const card = current ? cardOf(current) : null;
                if (card && shown(card)) {
                    place(card);
                    return;
                }
                const first = wide.matches ? firstShown() : null;
                if (first) {
                    select(first.dataset.kbId, { animate: true });
                } else if (current) {
                    close({ focus: false });
                }
            },
            /** Nouvel état d'une base (rafraîchissement en direct) : le schéma le dit. */
            state(id, st) {
                const spec = specOf(id);
                if (!spec) return;
                spec.state = { title: st.title || '', tone: st.tone || '' };
                if (Number(id) === current) paint(false);
            },
        };
    })();

    // ── Cartes de la liste : état en direct ──
    // Toutes les 3 s tant qu'une base travaille, toutes les 45 s au repos
    // (les « il y a 4 min » vieillissent, un passage programmé démarre) ;
    // en pause onglet caché, relancé à son retour. Le serveur renvoie le bloc
    // TOUT RENDU : on remplace, sans connaître un seul libellé.
    const LiveStatus = (() => {
        let timer    = null;
        let inflight = false;
        const lastHtml = new Map();

        function schedule(ms) {
            clearTimeout(timer);
            timer = setTimeout(refresh, ms);
        }

        function apply(b) {
            const card = document.querySelector('.kb-card[data-kb-id="' + b.id + '"]');
            if (!card) return; // base créée ailleurs : elle paraîtra au prochain chargement
            card.dataset.tone  = b.tone;
            card.dataset.state = b.state;
            listDiagram?.state(b.id, b);
            if (lastHtml.get(b.id) === b.html) return;
            lastHtml.set(b.id, b.html);
            const live = card.querySelector('.kb-live');
            if (live) live.outerHTML = b.html;
        }

        async function refresh() {
            clearTimeout(timer);
            if (document.hidden || inflight) return;
            inflight = true;
            let next = <?= (int)KbStatus::POLL_IDLE_MS ?>;
            try {
                const r = await fetch('/admin/knowledge-bases/status', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                const data = await r.json().catch(() => ({}));
                if (data && data.ok) {
                    (data.bases || []).forEach(apply);
                    const overview = document.getElementById('kbOverview');
                    if (overview && typeof data.overview_html === 'string') overview.outerHTML = data.overview_html;
                    next = Number(data.poll_ms) || next;
                }
            } catch (e) {
                next = 15000;
            } finally {
                inflight = false;
            }
            schedule(next);
        }

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refresh();
        });

        return {
            refresh,
            start(busy) { schedule(busy ? <?= (int)KbStatus::POLL_BUSY_MS ?> : <?= (int)KbStatus::POLL_IDLE_MS ?>); },
        };
    })();

    // ── « Synchroniser maintenant » : le bouton de la carte ──
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-kb-sync-now]');
        if (!btn) return;
        e.preventDefault();
        const kbId = Number(btn.dataset.kbSyncNow);
        if (!kbId || btn.disabled) return;
        btn.disabled = true;
        btn.classList.add('is-busy');
        try {
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            const r = await fetch('/admin/knowledge-bases/' + kbId + '/sync', {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await r.json().catch(() => ({}));
            if (!data.ok) {
                alert(data.error || <?= tj('Lancement impossible.') ?>);
                btn.disabled = false;
                btn.classList.remove('is-busy');
                return;
            }
        } catch (err) {
            alert(<?= tj('Erreur réseau. Réessayez.') ?>);
            btn.disabled = false;
            btn.classList.remove('is-busy');
            return;
        }
        // « En attente » arrive aussitôt ; le bouton disparaît avec le bloc
        // qui le portait, jusqu'à la fin du passage.
        LiveStatus.refresh();
    });

    // ── Liste : recherche + filtre par source + tri ──
    // Tout se joue sur les cartes DÉJÀ rendues (attributs data-*) : pas de
    // second rendu, donc jamais un périmètre d'accès qui diverge du serveur.
    const kbGrid    = document.querySelector('.kb-grid');
    const kbFilter  = document.getElementById('kbFilter');
    const kbSort    = document.getElementById('kbSort');
    const kbNoMatch = document.getElementById('kbNoMatch');
    const kbCards   = Array.from(document.querySelectorAll('.kb-card'));
    const srcBtns   = Array.from(document.querySelectorAll('.kb-srcbtn'));
    // L'ordre de rendu EST le tri par nom (ORDER BY kb.name côté SQL) :
    // « Nom (A → Z) » se rejoue donc sans comparateur, à l'identique.
    const kbInitialOrder = kbCards.slice();

    // Préférences mémorisées par utilisateur — navigateur partagé = pas de
    // fuite d'un compte à l'autre (même clé de nommage que `connectorPrefs`).
    const KB_PREFS_KEY = 'kbListPrefs:<?= (int)($currentUser['id'] ?? 0) ?>';
    let kbSrc = 'all';

    function readKbPrefs() {
        try { return JSON.parse(localStorage.getItem(KB_PREFS_KEY) || '{}') || {}; }
        catch (e) { return {}; }
    }
    function saveKbPrefs() {
        // Best-effort : navigation privée ou quota plein ne casse pas la page.
        try {
            localStorage.setItem(KB_PREFS_KEY, JSON.stringify({
                src:  kbSrc,
                sort: kbSort ? kbSort.value : 'name'
            }));
        } catch (e) { /* ignoré */ }
    }

    function kbNum(card, key) { return parseInt(card.dataset[key] || '0', 10) || 0; }

    const KB_COMPARATORS = {
        name:       null, // ordre de rendu (SQL)
        usage:      (a, b) => kbNum(b, 'usage') - kbNum(a, 'usage'),
        recent:     (a, b) => (b.dataset.lastused || '').localeCompare(a.dataset.lastused || ''),
        size:       (a, b) => kbNum(b, 'size') - kbNum(a, 'size'),
        docs:       (a, b) => kbNum(b, 'docs') - kbNum(a, 'docs'),
        assistants: (a, b) => kbNum(b, 'assistants') - kbNum(a, 'assistants'),
        created:    (a, b) => (b.dataset.created || '').localeCompare(a.dataset.created || '')
    };

    function applyKbView() {
        const q = kbFilter ? kbFilter.value.trim().toLowerCase() : '';
        let shown = 0;
        kbCards.forEach(card => {
            const okText = !q || (card.dataset.search || '').includes(q);
            const okSrc  = kbSrc === 'all' || card.dataset.src === kbSrc;
            const visible = okText && okSrc;
            card.hidden = !visible;
            if (visible) shown++;
        });
        if (kbNoMatch) kbNoMatch.hidden = (shown > 0 || kbCards.length === 0);

        // Tri : à égalité (deux bases jamais utilisées, même taille…), l'ordre
        // alphabétique tranche — sinon la liste se réordonnerait au hasard.
        const cmp = kbSort ? KB_COMPARATORS[kbSort.value] : null;
        const ordered = cmp
            ? kbInitialOrder.slice().sort((a, b) => cmp(a, b) || kbInitialOrder.indexOf(a) - kbInitialOrder.indexOf(b))
            : kbInitialOrder;
        if (kbGrid) ordered.forEach(card => kbGrid.appendChild(card));
        listDiagram?.refresh();
    }

    srcBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            kbSrc = btn.dataset.src || 'all';
            srcBtns.forEach(b => {
                const on = b === btn;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            saveKbPrefs();
            applyKbView();
        });
    });
    if (kbFilter) kbFilter.addEventListener('input', applyKbView);
    if (kbSort) kbSort.addEventListener('change', () => { saveKbPrefs(); applyKbView(); });
    const kbReset = document.getElementById('kbResetFilters');
    if (kbReset) {
        kbReset.addEventListener('click', () => {
            if (kbFilter) kbFilter.value = '';
            const allBtn = srcBtns.find(b => b.dataset.src === 'all');
            if (allBtn) allBtn.click(); else applyKbView();
        });
    }

    if (srcBtns.length || kbSort) {
        // Restauration : une source disparue (dernière base Drive supprimée)
        // ne doit pas laisser la liste filtrée sur un bouton absent, donc vide.
        const prefs = readKbPrefs();
        if (kbSort && prefs.sort && KB_COMPARATORS.hasOwnProperty(prefs.sort)) kbSort.value = prefs.sort;
        const wanted = srcBtns.find(b => b.dataset.src === prefs.src);
        if (wanted && prefs.src !== 'all') wanted.click(); else applyKbView();
    }

    // Sans schéma (script absent) : la carte d'une base modifiable ouvre sa fiche.
    if (!listDiagram) {
        kbCards.forEach(card => {
            if (!card.dataset.editUrl) return;
            card.classList.add('kb-card-clickable');
            card.addEventListener('click', (e) => {
                if (e.target.closest('button, form, a, input')) return;
                location.href = card.dataset.editUrl;
            });
        });
    }

    // État en direct : rapide dès le chargement si une base travaille déjà —
    // typiquement la base qu'on vient de créer, dont la première
    // synchronisation attend son tour.
    if (kbCards.length > 0) {
        LiveStatus.start(<?= json_encode(array_filter($kbStatuses, static fn(array $s): bool => !empty($s['busy'])) !== []) ?>);
    }
})();
</script>
</body>
</html>
