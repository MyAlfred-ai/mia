<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Élagage') ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'pruning'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <?php $ongletsGroupe = AdminTabs::DONNEES; require ROOT . '/templates/partials/admin-tabs.php'; ?>
    <h1 style="margin-bottom:4px"><?= te('Élagage') ?></h1>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:24px">
        <?= te("Supprimez les conversations et les créations trop anciennes, et récupérez l'espace disque qu'elles occupent. Rien ne s'exécute automatiquement : chaque élagage demande un clic.") ?>
    </p>

    <?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php
        $conv    = $stats['conversations'];
        $crea    = $stats['creations'];
        $disque  = $stats['disque'];
        $tuiles  = [
            [t('Conversations'),     Format::credits((int)$conv['total']),     Format::sizeGoMo((int)$conv['octets']),   'var(--accent)'],
            [t('dont épinglées'),    Format::credits((int)$conv['epinglees']), t('jamais élaguées'),                     '#4ade80'],
            [t('Créations'),         Format::credits((int)$crea['total']),     Format::sizeGoMo((int)$crea['octets']),   '#38bdf8'],
            [t('Médias sur disque'), Format::sizeGoMo((int)$disque['total']), t('images, vidéos, pièces jointes'),      '#fbbf24'],
            [t('dont orphelins'),    Format::credits((int)$disque['orphelins']), Format::sizeGoMo((int)$disque['orphelins_octets']), '#fb7185'],
        ];
    ?>

    <!-- ================= État des lieux ================= -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:12px;font-size:15px"><?= te('État des lieux') ?></h3>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-bottom:14px">
            <?php foreach ($tuiles as [$libelle, $valeur, $detail, $couleur]): ?>
                <div class="lg-stat">
                    <span class="lg-stat-value" style="color:<?= $couleur ?>"><?= htmlspecialchars($valeur) ?></span>
                    <span class="lg-stat-label"><?= htmlspecialchars($libelle) ?></span>
                    <span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($detail) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <p style="font-size:12px;color:var(--text-muted);line-height:1.6;margin-bottom:10px">
            <?= teb('Les volumes ci-dessus sont ceux des {b}données{/b} (texte des messages, pièces jointes, spécifications des créations) et des {b}fichiers{/b} réellement présents sur le disque.') ?>
            <?php if ($stats['mysql'] !== null): ?>
                <?= teb("La base occupe aujourd'hui {b}{taille}{/b} pour ces trois tables — cette occupation {b}ne diminue pas{/b} après une suppression : MySQL réutilise l'espace libéré pour les données suivantes au lieu de le rendre au système. C'est normal. L'espace disque des médias, lui, est bien rendu.", ['taille' => Format::sizeGoMo((int)$stats['mysql'])]) ?>
            <?php endif; ?>
        </p>

        <p style="font-size:12px;color:var(--text-muted)">
            <?= te('Mesure du {date}', ['date' => $stats['computed_at']]) ?> ·
            <a href="/admin/pruning?recalculer=1" style="color:var(--accent)">↻ <?= te('Recalculer') ?></a>
        </p>
    </div>

    <!-- ================= Réglages (auto-enregistrés) ================= -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Ancienneté à conserver') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">
            <?= te("Au-delà de ces durées, une conversation ou une création devient éligible à l'élagage.") ?>
        </p>

        <form method="POST" action="/admin/pruning/settings" id="pruningSettings" data-autosave>
            <?= Csrf::field() ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div class="form-group" style="flex:1 1 240px">
                    <label><?= te("Conversations — jours d'activité à conserver") ?></label>
                    <input type="number" name="conversations_days" class="form-input"
                           min="<?= (int)PruningService::JOURS_MIN ?>" max="<?= (int)PruningService::JOURS_MAX ?>"
                           value="<?= (int)$joursConversations ?>" required>
                    <p class="profile-hint">
                        <?= teb("Comptés depuis la {b}dernière activité{/b}, pas depuis la création : une conversation ouverte il y a un an mais utilisée hier n'est pas concernée.") ?>
                    </p>
                </div>
                <div class="form-group" style="flex:1 1 240px">
                    <label><?= te('Créations — jours à conserver') ?></label>
                    <input type="number" name="creations_days" class="form-input"
                           min="<?= (int)PruningService::JOURS_MIN ?>" max="<?= (int)PruningService::JOURS_MAX ?>"
                           value="<?= (int)$joursCreations ?>" required>
                    <p class="profile-hint">
                        <?= te('Comptés depuis la date de génération de la création.') ?>
                    </p>
                </div>
            </div>

            <div class="backup-danger" style="border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.07);margin-top:4px">
                <?= teb('Les conversations marquées {b}favorites{/b} ne sont jamais supprimées. « Favori » = {b}conversation épinglée{/b} par son propriétaire (icône punaise dans la liste des conversations, {n} maximum par utilisateur).', ['n' => (int)Conversation::MAX_PINNED]) ?>
            </div>

            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
        </form>
    </div>

    <!-- ================= Élaguer les conversations ================= -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Élaguer les conversations') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
            <?= teb('Supprime définitivement les conversations sans activité depuis {b}{n} jours{/b}, avec leurs messages, leurs créations et leurs fichiers.', ['n' => (int)$stats['jours_conversations']]) ?>
        </p>

        <div class="backup-danger" data-pruning-impact>
            <strong>⚠ <?= te('Action irréversible.') ?></strong>
            <?= teb('À ce jour, {b}{n} conversation(s){/b} ({taille}) dépassent {jours} jours et seraient supprimées.', ['n' => Format::credits((int)$conv['eligibles']), 'taille' => Format::sizeGoMo((int)$conv['octets_eligibles']), 'jours' => (int)$stats['jours_conversations']]) ?>
            <?= teb('{b}{n} conversation(s) épinglée(s){/b} seront conservées, ainsi que celles dont une génération est en cours.', ['n' => Format::credits((int)$conv['epinglees'])]) ?>
            <?= te("L'historique de consommation et les quotas ne sont pas affectés.") ?>
            <br>
            <?= teb("La sauvegarde de {app} ne couvre {b}pas{/b} les conversations : seule une sauvegarde MySQL prise hors de l'application permettrait de revenir en arrière.", ['app' => Settings::get('app_name', 'MIA')]) ?>
        </div>

        <form method="POST" action="/admin/pruning/conversations" style="margin-top:14px" data-pruning-action
              data-confirm-message="<?= te('Tapez {mot} pour confirmer la suppression.', ['mot' => 'ELAGUER']) ?>"
              onsubmit="return this.confirm.value.trim().toUpperCase() === 'ELAGUER' || (alert(this.dataset.confirmMessage), false);">
            <?= Csrf::field() ?>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="margin:0;flex:0 1 220px">
                    <label><?= teb('Tapez {b}{mot}{/b} pour confirmer', ['mot' => 'ELAGUER']) ?></label>
                    <input type="text" name="confirm" class="form-input" placeholder="ELAGUER" autocomplete="off" required>
                </div>
                <button type="submit" class="btn-primary" style="background:#dc2626;border-color:#dc2626;flex-shrink:0">
                    <?= te('Élaguer les conversations') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- ================= Élaguer les créations ================= -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Élaguer les créations') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
            <?= teb('Supprime les images, vidéos, pages web, présentations et itinéraires générés il y a plus de {b}{n} jours{/b}. La conversation, elle, reste entière.', ['n' => (int)$stats['jours_creations']]) ?>
        </p>

        <div class="backup-danger" data-pruning-impact>
            <strong>⚠ <?= te('Action irréversible.') ?></strong>
            <?= teb('À ce jour, {b}{n} création(s){/b} ({taille}) dépassent {jours} jours.', ['n' => Format::credits((int)$crea['eligibles']), 'taille' => Format::sizeGoMo((int)$crea['octets_eligibles']), 'jours' => (int)$stats['jours_creations']]) ?>
            <br>
            <?= str_replace(['{i}', '{/i}'], ['<em>', '</em>'], teb("Le message n'est pas supprimé : son contenu est remplacé par une mention « {i}Création élaguée le …{/i} », le fichier part du disque et le lien de partage est révoqué. Le fil de conversation reste donc continu, sans image cassée ni lien mort. Quand une réponse rédigée accompagne les images, {b}le texte est conservé{/b} — seules les images sont retirées.")) ?>
        </div>

        <form method="POST" action="/admin/pruning/creations" style="margin-top:14px" data-pruning-action
              data-confirm-message="<?= te('Tapez {mot} pour confirmer la suppression.', ['mot' => 'ELAGUER']) ?>"
              onsubmit="return this.confirm.value.trim().toUpperCase() === 'ELAGUER' || (alert(this.dataset.confirmMessage), false);">
            <?= Csrf::field() ?>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="margin:0;flex:0 1 220px">
                    <label><?= teb('Tapez {b}{mot}{/b} pour confirmer', ['mot' => 'ELAGUER']) ?></label>
                    <input type="text" name="confirm" class="form-input" placeholder="ELAGUER" autocomplete="off" required>
                </div>
                <button type="submit" class="btn-primary" style="background:#dc2626;border-color:#dc2626;flex-shrink:0">
                    <?= te('Élaguer les créations') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- ================= Balayage des fichiers orphelins ================= -->
    <div class="card" style="margin-bottom:24px">
        <h3 style="margin-bottom:4px;font-size:15px"><?= te('Nettoyer les fichiers orphelins') ?></h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
            <?= teb('Supprime du disque les médias que plus aucun message ne référence — notamment ceux des conversations que les utilisateurs ont supprimées eux-mêmes. Ce balayage tourne {b}aussi automatiquement{/b} à la fin des deux élagages ci-dessus.') ?>
        </p>

        <div class="backup-danger" data-pruning-impact>
            <strong>⚠ <?= te('Action irréversible.') ?></strong>
            <?= teb('{b}{n} fichier(s){/b} ({taille}) ne sont référencés par aucun message et seraient supprimés.', ['n' => Format::credits((int)$disque['orphelins']), 'taille' => Format::sizeGoMo((int)$disque['orphelins_octets'])]) ?>
            <?= te('Les fichiers de moins de 24 heures sont toujours conservés : une génération peut être en cours.') ?>
        </div>

        <form method="POST" action="/admin/pruning/orphans" style="margin-top:14px" data-pruning-action
              data-confirm-message="<?= te('Tapez {mot} pour confirmer le nettoyage.', ['mot' => 'ELAGUER']) ?>"
              onsubmit="return this.confirm.value.trim().toUpperCase() === 'ELAGUER' || (alert(this.dataset.confirmMessage), false);">
            <?= Csrf::field() ?>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="margin:0;flex:0 1 220px">
                    <label><?= teb('Tapez {b}{mot}{/b} pour confirmer', ['mot' => 'ELAGUER']) ?></label>
                    <input type="text" name="confirm" class="form-input" placeholder="ELAGUER" autocomplete="off" required>
                </div>
                <button type="submit" class="btn-primary" style="background:#dc2626;border-color:#dc2626;flex-shrink:0">
                    <?= te('Nettoyer les fichiers orphelins') ?>
                </button>
            </div>
        </form>
    </div>

    <p style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
        <?= te("Un élagage volumineux s'arrête au bout de quelques dizaines de secondes et annonce ce qu'il reste : relancez-le autant de fois que nécessaire. Rien ne s'exécute automatiquement — aucun élagage n'a lieu sans un clic sur l'un des boutons ci-dessus.") ?>
    </p>
</div>

<script>
(function () {
    'use strict';

    var reglages = document.getElementById('pruningSettings');

    /*
     * Le nombre de jours est auto-enregistré (débounce), les élagages sont des
     * boutons. Sans cette garde : l'admin tape « 30 », clique aussitôt
     * « Élaguer », et le serveur lit le réglage STOCKÉ (120) — il n'élague pas
     * ce que l'écran annonçait, en silence et dans les deux sens. On force donc
     * l'envoi en attente à partir AVANT de soumettre, et on refuse de partir
     * s'il n'a pas abouti.
     */
    document.querySelectorAll('form[data-pruning-action]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.MiaAutosave || !reglages || !window.MiaAutosave.isDirty(reglages)) {
                return; // rien en attente : la soumission suit son cours normal
            }
            e.preventDefault();

            window.MiaAutosave.settle(reglages).then(function () {
                if (window.MiaAutosave.isDirty(reglages)) {
                    alert(<?= tj("Le nombre de jours n'a pas pu être enregistré. Vérifiez la valeur avant d'élaguer.") ?>);
                    return;
                }
                // Détaché : un envoi différé ne doit pas repartir après la
                // redirection et reposter l'état d'avant l'élagage.
                window.MiaAutosave.detach(reglages);
                form.submit();
            });
        });
    });

    /*
     * Les aperçus d'impact sont rendus côté serveur avec les seuils ENREGISTRÉS.
     * Changer les jours sans recharger les rend faux : on le dit plutôt que de
     * laisser lire un chiffre périmé.
     */
    if (reglages) {
        reglages.addEventListener('mia:autosaved', function () {
            document.querySelectorAll('[data-pruning-impact]').forEach(function (bloc) {
                if (bloc.dataset.stale === '1') { return; }
                bloc.dataset.stale = '1';
                bloc.style.opacity = '.65';
                var note = document.createElement('div');
                note.style.marginTop = '6px';
                note.style.fontWeight = '600';
                note.textContent = <?= tj("Seuil modifié — ces chiffres datent d'avant.") ?> + ' ';
                var lien = document.createElement('a');
                lien.href = '/admin/pruning?recalculer=1';
                lien.style.color = 'var(--accent)';
                lien.textContent = '↻ ' + <?= tj('Recalculer') ?>;
                note.appendChild(lien);
                bloc.appendChild(note);
            });
        });
    }
})();
</script>
</body>
</html>
