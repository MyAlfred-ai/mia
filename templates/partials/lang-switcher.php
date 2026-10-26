<?php
/**
 * Sélecteur de langue — commande flottante, en haut à droite de l'écran.
 *
 * Emplacement : `position: fixed`, comme le pendant `.sidebar-reopen-btn` qui
 * occupe déjà le haut à GAUCHE. Il n'est donc rattaché à aucune barre : la
 * toolbar du chat disparaît en mode assistant, et les écrans d'administration
 * n'en ont pas — un contrôle ancré à l'une ou à l'autre serait absent la moitié
 * du temps.
 *
 * Une seule instance par page, un seul markup : sur mobile le bouton se réduit
 * au drapeau et le header réserve sa place par un `padding-right` (cf. main.css),
 * plutôt que d'exister en double dans le menu du compte — deux exemplaires du
 * même contrôle divergent au premier ajustement.
 *
 * Comportement : public/assets/js/user-menu.js, le seul script chargé par
 * toutes les pages applicatives.
 */
$lsCourante = I18n::locale();
$lsInfo     = I18n::localeInfo($lsCourante);
?>
<div class="lang-switcher" id="langSwitcher">
    <button type="button" class="lang-switcher-btn" id="langSwitcherBtn"
            aria-haspopup="listbox" aria-expanded="false" aria-controls="langSwitcherList"
            title="<?= te('Langue de l’interface') ?>" aria-label="<?= te('Langue de l’interface') ?>">
        <span class="lang-switcher-flag" aria-hidden="true"><?= $lsInfo['drapeau'] ?></span>
        <span class="lang-switcher-code"><?= htmlspecialchars($lsInfo['court']) ?></span>
        <svg class="lang-switcher-caret" width="12" height="12" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </button>
    <ul class="lang-switcher-list" id="langSwitcherList" role="listbox"
        aria-label="<?= te('Langue de l’interface') ?>" hidden>
        <?php foreach (I18n::LOCALES as $lsCode => $lsMeta):
            $lsActive = $lsCode === $lsCourante; ?>
        <li role="none">
            <button type="button" role="option" class="lang-switcher-option<?= $lsActive ? ' is-active' : '' ?>"
                    data-lang="<?= htmlspecialchars($lsCode, ENT_QUOTES) ?>"
                    aria-selected="<?= $lsActive ? 'true' : 'false' ?>">
                <span class="lang-switcher-flag" aria-hidden="true"><?= $lsMeta['drapeau'] ?></span>
                <span class="lang-switcher-name"><?= htmlspecialchars($lsMeta['nom']) ?></span>
                <?php if ($lsActive): ?>
                <svg class="lang-switcher-check" width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                <?php endif; ?>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
