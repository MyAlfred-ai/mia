<?php
/**
 * « Nouvelle table » — une table PERMANENTE, rangée avec les bases de
 * connaissances (AdminController::newKnowledgeBase, ?genre=table).
 *
 * La même vue que la fiche (piste D) : barre du haut, menu des sections, une
 * carte par section — Identité, Contenu (un classeur déposé, ou des colonnes
 * saisies à la main), Qui la voit, MIA et assistants — puis la Vue
 * d'ensemble, le schéma de la table à venir (kb-fiche.js). La troisième façon
 * de commencer une table — la garder depuis une conversation — se fait dans le
 * volet « Tables » du chat. Créer reste un geste volontaire : aucune écriture
 * avant « Créer la table » (KbTableController::create).
 *
 * Attend : $manageableProfiles, $shareableUsers, $shareableGroups, $diagram,
 * $success, $error.
 */
$tnMaxRows = Format::credits(KbTableStore::MAX_ROWS);
$tnMaxCols = (int)WorkTableOps::MAX_COLUMNS;
$tnMaxMo   = (int)round(LoopFiles::MAX_BYTES / 1048576);
$version   = static fn(string $file): int => (int)(@filemtime(ROOT . $file) ?: 1);
$visTexts  = [
    KnowledgeBase::VIS_PRIVATE => KbPages::visibility(KnowledgeBase::VIS_PRIVATE),
    KnowledgeBase::VIS_COMMON  => KbPages::visibility(KnowledgeBase::VIS_COMMON),
    KnowledgeBase::VIS_PUBLIC  => KbPages::visibility(KnowledgeBase::VIS_PUBLIC),
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Nouvelle table') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= $version('/public/assets/css/main.css') ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
    <link rel="stylesheet" href="/assets/css/kb-pages.css?v=<?= $version('/public/assets/css/kb-pages.css') ?>">
    <link rel="stylesheet" href="/assets/css/kb-table.css?v=<?= $version('/public/assets/css/kb-table.css') ?>">
</head>
<body class="admin-page">
<?php $page = 'knowledge-bases'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content pf-content kb-content kbx-page">
<div class="kbx kbx-fiche is-table" id="kbFiche">

    <div class="kbx-top">
        <div class="kbx-top-in">
            <div class="kbx-crumb">
                <a href="/admin/knowledge-bases" title="<?= te('Toutes les bases') ?>" aria-label="<?= te('Toutes les bases') ?>"><?= KbPages::icon('back', '') ?></a>
                <div>
                    <small><?= te('Nouvelle table') ?></small>
                    <b id="kbFicheTitle" class="is-missing" data-empty="<?= te('Sans nom') ?>"><?= te('Sans nom') ?></b>
                </div>
            </div>
            <div class="kbx-top-tools">
                <span class="kbx-top-hint"><?= te('La table existe dès que vous cliquez sur « Créer la table ».') ?></span>
                <a href="/admin/knowledge-bases" class="kbx-btn is-sm"><?= te('Annuler') ?></a>
                <button type="submit" class="kbx-btn is-sm is-primary" form="kbTableNew"><?= te('Créer la table') ?></button>
            </div>
        </div>
    </div>

    <div class="kbx-layout">
        <nav class="kbx-nav" aria-label="<?= te('Sections de la fiche') ?>">
            <a href="#identite" class="is-on"><?= KbPages::icon('id', '') ?><span class="kbx-nl"><?= te('Identité') ?></span></a>
            <a href="#contenu"><?= KbPages::icon('table', '') ?><span class="kbx-nl"><?= te('Contenu') ?></span></a>
            <a href="#visibilite"><?= KbPages::visibilityIcon(KnowledgeBase::VIS_COMMON) ?><span class="kbx-nl"><?= te('Qui la voit') ?></span></a>
            <a href="#assistants"><?= KbPages::icon('robot', '') ?><span class="kbx-nl"><?= te('MIA et assistants') ?></span></a>
            <span class="kbx-nav-sep" aria-hidden="true"></span>
            <a href="#ensemble"><?= KbPages::icon('map', '') ?><span class="kbx-nl"><?= te('Vue d\'ensemble') ?></span></a>
        </nav>

        <div class="kbx-sections">
            <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" action="/admin/knowledge-bases/table" id="kbTableNew" enctype="multipart/form-data" class="kb-form">
                <?= Csrf::field() ?>

                <section class="kbx-sec" id="identite" aria-labelledby="kbtSecIdentity">
                    <h2 id="kbtSecIdentity"><?= te('Identité') ?></h2>
                    <p class="kbx-lead"><?= te('Comment la table apparaît dans la liste et dans le sélecteur du chat.') ?></p>
                    <div class="kbx-fields">
                        <div class="form-group">
                            <label for="kbtName"><?= te('Nom') ?></label>
                            <input type="text" name="name" id="kbtName" class="form-input" required maxlength="190"
                                   placeholder="<?= te('Ex. : Clients 2026') ?>" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="kbtDescription"><?= te('Description') ?></label>
                            <input type="text" name="description" id="kbtDescription" class="form-input"
                                   placeholder="<?= te('Ex. : Clients actifs, leur ville, leur chiffre et leur commercial') ?>">
                        </div>
                    </div>
                </section>

                <section class="kbx-sec" id="contenu" aria-labelledby="kbtSecContent">
                    <h2 id="kbtSecContent"><?= te('Contenu') ?></h2>
                    <p class="kbx-lead"><?= te('Des lignes que MIA compte, filtre et croise sans en oublier : un fichier clients, une grille de tarifs, un suivi.') ?></p>
                    <div class="kbx-choices" role="radiogroup" aria-label="<?= te('D\'où viennent les lignes') ?>">
                        <label class="kbx-choice">
                            <input type="radio" name="table_source" value="file" checked data-kbt-new-source>
                            <span class="kbx-choice-top"><span class="kbx-choice-ico"><?= KbPages::icon('upload', '') ?></span><span class="kbx-radio"></span></span>
                            <b><?= te('Importer un fichier') ?></b>
                            <small><?= te('Excel, OpenDocument ou CSV : sa première ligne donne les colonnes.') ?></small>
                        </label>
                        <label class="kbx-choice">
                            <input type="radio" name="table_source" value="manual" data-kbt-new-source>
                            <span class="kbx-choice-top"><span class="kbx-choice-ico"><?= KbPages::icon('columns', '') ?></span><span class="kbx-radio"></span></span>
                            <b><?= te('Partir de colonnes vides') ?></b>
                            <small><?= te('Vous nommez les colonnes, puis ajoutez les lignes dans la fiche.') ?></small>
                        </label>
                    </div>

                    <div class="kbx-panel" data-kbt-new-block="file">
                        <div class="form-group">
                            <label for="kbtFile"><?= te('Fichier') ?></label>
                            <input type="file" name="file" id="kbtFile" class="form-input" accept=".xlsx,.xlsm,.xls,.ods,.csv,.tsv">
                            <p class="profile-hint"><?= te('Excel (.xlsx, .xls), OpenDocument (.ods) ou CSV, {max} Mo au plus. La première ligne remplie donne le nom des colonnes ; d\'un classeur à plusieurs onglets, le premier est lu.', ['max' => $tnMaxMo]) ?></p>
                        </div>
                    </div>
                    <div class="kbx-panel" data-kbt-new-block="manual" hidden>
                        <div class="form-group">
                            <label for="kbtColumns"><?= te('Colonnes') ?></label>
                            <input type="text" name="columns" id="kbtColumns" class="form-input" disabled
                                   placeholder="<?= te('Nom, Email, Ville, CA 2026') ?>" autocomplete="off">
                            <p class="profile-hint"><?= te('Séparez les colonnes par des virgules. Vous pourrez en ajouter, les renommer et choisir leur type (texte, nombre, date) dans la fiche.') ?></p>
                        </div>
                    </div>
                    <p class="kbx-cap">
                        <?= teb('{b}{lignes} lignes et {colonnes} colonnes au plus.{/b} Un fichier plus long est refusé, avec le nombre de lignes trouvé — jamais coupé sans le dire.', [
                            'lignes' => $tnMaxRows, 'colonnes' => $tnMaxCols,
                        ]) ?>
                        <?= te('Une table établie par MIA dans une conversation se garde aussi : ouvrez le volet « Tables » du chat, puis « Garder ».') ?>
                    </p>
                </section>

                <section class="kbx-sec" id="visibilite" aria-labelledby="kbtSecVisibility">
                    <h2 id="kbtSecVisibility"><?= te('Qui la voit') ?></h2>
                    <p class="kbx-lead"><?= te('Ceux qui la voient la cochent dans le chat et la lient à leurs assistants. Vous pouvez changer d\'avis à tout moment.') ?></p>
                    <input type="hidden" name="visibility_form" value="1">
                    <div class="kbx-access" role="radiogroup" aria-label="<?= te('Qui la voit') ?>">
                        <?php foreach ([KnowledgeBase::VIS_PRIVATE, KnowledgeBase::VIS_COMMON, KnowledgeBase::VIS_PUBLIC] as $vKey): ?>
                            <label class="kbx-acc">
                                <input type="radio" name="visibility" value="<?= htmlspecialchars($vKey) ?>" data-kbt-visibility<?= $vKey === KnowledgeBase::VIS_COMMON ? ' checked' : '' ?>>
                                <span class="kbx-acc-top"><span class="kbx-acc-ic"><?= KbPages::visibilityIcon($vKey) ?></span><span class="kbx-radio"></span></span>
                                <b><?= htmlspecialchars($visTexts[$vKey]['label']) ?></b>
                                <small><?= htmlspecialchars($visTexts[$vKey]['hint']) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="kbx-panel" data-kbt-share>
                        <?php if (!empty($shareableGroups)): ?>
                            <span class="kb-share-label"><?= te('Groupes') ?></span>
                            <div class="ug-check-list">
                                <?php foreach ($shareableGroups as $sg): $gid = (int)$sg['id']; ?>
                                    <label class="ug-check-item kb-share-item">
                                        <input type="checkbox" name="share_group_ids[]" value="<?= $gid ?>">
                                        <span class="ug-member-id"><span class="ug-member-name"><?= htmlspecialchars((string)$sg['name']) ?></span></span>
                                        <span class="kb-share-manage"><input type="checkbox" name="share_group_manage[]" value="<?= $gid ?>"> <?= te('peut modifier') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($shareableUsers)): ?>
                            <span class="kb-share-label" style="margin-top:12px"><?= te('Personnes') ?></span>
                            <div class="ug-check-list">
                                <?php foreach ($shareableUsers as $su): $uid = (int)$su['id']; ?>
                                    <label class="ug-check-item kb-share-item">
                                        <input type="checkbox" name="share_user_ids[]" value="<?= $uid ?>">
                                        <span class="ug-member-id"><span class="ug-member-name"><?= htmlspecialchars((string)$su['name']) ?></span><span class="ug-member-email"><?= htmlspecialchars((string)$su['email']) ?></span></span>
                                        <span class="kb-share-manage"><input type="checkbox" name="share_user_manage[]" value="<?= $uid ?>"> <?= te('peut modifier') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (empty($shareableGroups) && empty($shareableUsers)): ?>
                            <p class="kbx-muted"><?= te('Aucun groupe ni personne à qui la partager.') ?></p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="kbx-sec" id="assistants" aria-labelledby="kbtSecMia">
                    <h2 id="kbtSecMia"><?= te('MIA et assistants') ?></h2>
                    <p class="kbx-lead"><?= te('MIA lit toujours une table cochée dans le chat ou liée à un assistant. Écrire dedans se fait toujours sous une carte d\'approbation.') ?></p>
                    <div class="kbx-access" style="grid-template-columns:repeat(2,minmax(0,1fr))" role="radiogroup" aria-label="<?= te('MIA peut') ?>">
                        <label class="kbx-acc">
                            <input type="radio" name="mia_write" value="">
                            <span class="kbx-acc-top"><span class="kbx-acc-ic"><?= KbPages::icon('search', '') ?></span><span class="kbx-radio"></span></span>
                            <b><?= te('Lire seulement') ?></b>
                            <small><?= te('Elle compte et croise, sans rien changer') ?></small>
                        </label>
                        <label class="kbx-acc">
                            <input type="radio" name="mia_write" value="1" checked>
                            <span class="kbx-acc-top"><span class="kbx-acc-ic"><?= KbPages::icon('pencil', '') ?></span><span class="kbx-radio"></span></span>
                            <b><?= te('Lire, et écrire après mon accord') ?></b>
                            <small><?= te('Chaque modification attend une carte d\'approbation') ?></small>
                        </label>
                    </div>
                    <?php if (!empty($manageableProfiles)): ?>
                        <div class="kbx-block">
                            <span class="kb-share-label"><?= te('Assistants liés') ?></span>
                            <div class="ug-check-list kb-pick-list">
                                <?php foreach ($manageableProfiles as $mp):
                                    $mpName  = trim((string)$mp['name']);
                                    $mpColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($mp['color'] ?? '')) === 1 ? (string)$mp['color'] : '#64748b'; ?>
                                    <label class="ug-check-item">
                                        <input type="checkbox" name="profile_ids[]" value="<?= (int)$mp['id'] ?>">
                                        <span class="kb-pick-ava is-dot" style="background:<?= htmlspecialchars($mpColor) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($mpName, 0, 1))) ?></span>
                                        <span class="ug-member-id"><span class="ug-member-name"><?= htmlspecialchars($mpName) ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </form>

            <section class="kbx-sec" id="ensemble" aria-labelledby="kbtSecEnsemble">
                <h2 id="kbtSecEnsemble"><?= te('Vue d\'ensemble') ?></h2>
                <p class="kbx-lead"><?= te('Ce que sera la table, mis à jour à chaque réglage. Touchez un élément pour aller le régler.') ?></p>
                <div class="kbx-diagram sched-diagram" id="kbFormDiagram"></div>
            </section>

            <div class="kbx-endbar">
                <span><?= te('Vous pourrez tout modifier ensuite, depuis sa page.') ?></span>
                <a href="/admin/knowledge-bases" class="kbx-btn"><?= te('Annuler') ?></a>
                <button type="submit" class="kbx-btn is-primary" form="kbTableNew"><?= te('Créer la table') ?></button>
            </div>
        </div>
    </div>
</div>
</div>
<script>
window.KB_FICHE = <?= json_encode([
    'catalog' => $diagram['catalog'],
    'spec'    => $diagram['spec'],
    'jump'    => [
        'docs'       => t('Modifier dans « Contenu »'),
        'assistant'  => t('Modifier dans « Identité »'),
        'assistants' => t('Modifier dans « MIA et assistants »'),
        'sharing'    => t('Modifier dans « Qui la voit »'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/diagram.js?v=<?= $version('/public/assets/js/diagram.js') ?>"></script>
<script src="/assets/js/kb-diagram.js?v=<?= $version('/public/assets/js/kb-diagram.js') ?>"></script>
<script src="/assets/js/kb-table.js?v=<?= $version('/public/assets/js/kb-table.js') ?>"></script>
<script src="/assets/js/kb-fiche.js?v=<?= $version('/public/assets/js/kb-fiche.js') ?>"></script>
</body>
</html>
