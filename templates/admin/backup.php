<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Sauvegarde') ?></title><link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'backup'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php $ongletsGroupe = AdminTabs::DONNEES; require ROOT . '/templates/partials/admin-tabs.php'; ?>
    <h1 style="margin-bottom:4px"><?= te('Sauvegarde') ?></h1>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:24px">
        <?= te('Exportez la configuration complète de {app} dans une archive, ou restaurez-la depuis une sauvegarde précédente.', ['app' => Settings::get('app_name', 'MIA')]) ?>
    </p>

    <?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Export -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Exporter la configuration') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
            <?= teb('Archive ZIP contenant {b}{n} tables{/b} et les fichiers associés (documents des assistants et des bases, avatars, logos).', ['n' => count($coveredTables)]) ?>
        </p>

        <div class="backup-scope">
            <div class="backup-scope-col">
                <div class="backup-scope-title">✓ <?= te('Inclus') ?></div>
                <ul>
                    <li><?= te('Utilisateurs : comptes, rôles, quotas, mémoires « MIA pour vous », connexions Google') ?></li>
                    <li><?= te('Groupes et tous leurs droits (assistants, outils, modèles)') ?></li>
                    <li><?= te('Assistants : réglages, documents de référence, liaisons') ?></li>
                    <li><?= te('Bases de connaissances : documents, extraits, partages, configuration de synchronisation') ?></li>
                    <li><?= te('Outils de génération (image, vidéo, HTML, présentation…)') ?></li>
                    <li><?= te('Clés API (chiffrées) et modèles activés') ?></li>
                    <li><?= te("Paramètres de l'application (identité, crédits, connecteurs…)") ?></li>
                </ul>
            </div>
            <div class="backup-scope-col">
                <div class="backup-scope-title">✗ <?= te('Exclus') ?></div>
                <ul>
                    <li><?= te('Conversations, messages et médias générés') ?></li>
                    <li><?= te('Historique de consommation et résultats de tests') ?></li>
                    <li><?= te('Files de tâches en cours') ?></li>
                    <li><?= te('Vecteurs Qdrant') ?> — <em><?= te('reconstruits automatiquement à la restauration') ?></em></li>
                </ul>
            </div>
        </div>

        <p style="font-size:12px;color:var(--text-muted);margin:12px 0">
            ⚠ <?= str_replace('{cle}', '<code>ENCRYPTION_KEY</code>', te("L'archive contient des secrets chiffrés avec la clé de CETTE instance ({cle}) : conservez-la en lieu sûr, et restaurez-la sur une instance portant la même clé pour retrouver clés API et connexions (sinon, elles seront à re-saisir).")) ?>
            <?= te('Toute table ajoutée par une future version est incluse automatiquement.') ?>
        </p>

        <form method="POST" action="/admin/backup/export">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= te('Télécharger la sauvegarde') ?>
            </button>
        </form>
    </div>

    <!-- Import -->
    <div class="card">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Restaurer une sauvegarde') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
            <?= te("Remplace la configuration actuelle par celle de l'archive. Tout-ou-rien côté base de données : en cas d'erreur, rien n'est modifié.") ?>
        </p>

        <div class="backup-danger">
            <strong>⚠ <?= te('Action destructive.') ?></strong>
            <?= teb("Les utilisateurs, groupes, assistants, bases de connaissances, outils, clés et paramètres actuels seront {b}remplacés{/b} par le contenu de la sauvegarde. Les conversations existantes sont conservées, mais peuvent référencer des assistants qui n'existent plus dans la sauvegarde restaurée.") ?>
            <?= te("Exportez d'abord une sauvegarde de l'état actuel.") ?>
        </div>

        <form method="POST" action="/admin/backup/import" enctype="multipart/form-data" style="margin-top:14px"
              data-confirm-message="<?= te('Tapez {mot} pour confirmer la restauration.', ['mot' => 'REMPLACER']) ?>"
              onsubmit="return this.confirm.value.trim().toUpperCase() === 'REMPLACER' || (alert(this.dataset.confirmMessage), false);">
            <?= Csrf::field() ?>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="margin:0;flex:1 1 260px">
                    <label><?= te('Archive de sauvegarde (.zip)') ?></label>
                    <input type="file" name="backup_file" class="form-input" accept=".zip,application/zip" required>
                </div>
                <div class="form-group" style="margin:0;flex:0 1 220px">
                    <label><?= teb('Tapez {b}{mot}{/b} pour confirmer', ['mot' => 'REMPLACER']) ?></label>
                    <input type="text" name="confirm" class="form-input" placeholder="REMPLACER" autocomplete="off" required>
                </div>
                <button type="submit" class="btn-primary" style="background:#dc2626;border-color:#dc2626;flex-shrink:0"><?= te('Restaurer') ?></button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
