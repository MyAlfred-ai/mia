<?php
/**
 * Modale « Nouveautés » — ouverte par le bouton #versionBtn (pied du chat et
 * page À propos).
 *
 * PARTIAL COMMUN, et c'est le but : la modale vivait en double, recopiée dans
 * chat.js et dans templates/about/index.php. Deux exemplaires du même écran
 * divergent au premier ajustement, et l'un des deux part en production sans
 * qu'on le voie.
 *
 * Le gabarit ne rend AUCUNE nouveauté : elles sont chargées à l'ouverture par
 * whats-new.js depuis /changelog.json (qui n'est pas dans le cache du
 * navigateur). Seul l'identifiant de la plus récente est injecté côté serveur,
 * pour que la pastille « non lu » s'affiche sans une requête par page.
 *
 * Attend $currentUser dans la portée appelante (facultatif).
 */
$wnUser    = $currentUser ?? Session::currentUser();
$wnDernier = Nouveautes::dernierId(ROOT);
$wnJs      = '/assets/js/whats-new.js?v=' . (@filemtime(ROOT . '/public/assets/js/whats-new.js') ?: 0);
?>
<div class="modal" id="versionModal" style="display:none">
    <div class="modal-box modal-box-compact wn-box">
        <h3 class="wn-title"><?= te('Nouveautés') ?></h3>
        <p class="wn-subtitle">
            <?= te('Ce que {app} sait faire de plus.', ['app' => Settings::get('app_name', 'MIA')]) ?>
            <?= te('Version installée :') ?> <strong>v<?= htmlspecialchars(APP_VERSION) ?></strong>
        </p>

        <div id="versionList" class="wn-list">
            <p class="wn-empty"><?= te('Chargement…') ?></p>
        </div>

        <!-- Sujets de commit : lecture d'ingénierie, sans intérêt pour qui
             utilise l'application. Rendu par le JS, replié, et seulement pour
             les comptes de l'éditeur. -->
        <details id="versionTech" class="wn-tech" hidden>
            <summary><?= te('Détail technique') ?></summary>
            <div id="versionTechList" class="wn-tech-list"></div>
        </details>

        <div class="modal-actions">
            <button type="button" class="btn-primary" id="versionClose">Fermer</button>
        </div>
    </div>
</div>
<script>
window.IS_STAFF = <?= json_encode(Settings::isStaffEmail($wnUser['email'] ?? null)) ?>;
window.WHATS_NEW_LAST_ID = <?= json_encode($wnDernier) ?>;
window.WHATS_NEW_USER_ID = <?= json_encode((int) ($wnUser['id'] ?? 0)) ?>;
</script>
<script src="<?= htmlspecialchars($wnJs) ?>" defer></script>
