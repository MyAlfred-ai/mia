<?php
/**
 * Onglets entre pages sœurs d'une même entrée du menu (AdminTabs, source unique ;
 * libellés traduits par ses accesseurs).
 * Attend : $page (valeur de la page courante), $ongletsGroupe (AdminTabs::DIAGNOSTIC…).
 */
$ongletsListe = AdminTabs::onglets((string)($ongletsGroupe ?? ''));
if ($ongletsListe !== []):
?>
<nav class="admin-tabs" aria-label="<?= htmlspecialchars(AdminTabs::libelleText((string)$ongletsGroupe)) ?>">
    <?php foreach ($ongletsListe as $ongletPage => [$ongletHref]): ?>
        <a href="<?= htmlspecialchars($ongletHref) ?>" class="admin-tab<?= ($page ?? '') === $ongletPage ? ' active' : '' ?>"<?= ($page ?? '') === $ongletPage ? ' aria-current="page"' : '' ?>><?= htmlspecialchars(AdminTabs::ongletText((string)$ongletPage)) ?></a>
    <?php endforeach; ?>
</nav>
<script>
// Rangée défilante sur mobile : l'onglet actif est ramené dans la vue
// (le quatrième onglet du Diagnostic sortait de l'écran à 390 px).
(function (nav) {
    var actif = nav && nav.querySelector('.admin-tab.active');
    if (actif && nav.scrollWidth > nav.clientWidth) {
        nav.scrollLeft = Math.max(0, actif.offsetLeft - (nav.clientWidth - actif.offsetWidth) / 2);
    }
})(document.currentScript && document.currentScript.previousElementSibling);
</script>
<?php endif; ?>
