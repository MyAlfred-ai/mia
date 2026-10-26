<?php
/**
 * Synthèse de la liste des bases, à côté du total : ce qui tourne, ce qui a
 * échoué, ce qui est à vérifier — sans avoir à parcourir toutes les cartes.
 * Vide quand tout va bien (le calme n'a pas besoin d'être annoncé).
 *
 * Rendu au chargement ET renvoyé par `GET /admin/knowledge-bases/status`
 * (`overview_html`) : même partiel, jamais de libellé côté JavaScript — il
 * suit donc la locale de la requête, comme le reste de la page.
 *
 * Attend : $kbStatuses (id => KbStatus::summarize).
 */
$ovBusy = 0;
$ovErr  = 0;
$ovWarn = 0;
foreach ($kbStatuses as $ovSt) {
    if (!empty($ovSt['busy'])) {
        $ovBusy++;
    } elseif ($ovSt['tone'] === KbStatus::TONE_ERROR) {
        $ovErr++;
    } elseif ($ovSt['tone'] === KbStatus::TONE_WARN) {
        $ovWarn++;
    }
}
?>
<span class="kb-overview" id="kbOverview" aria-live="polite">
    <?php if ($ovBusy > 0): ?>
        <span class="kb-overview-item" data-tone="busy"><span class="kb-spin" aria-hidden="true"></span><?= te('{n} en cours', ['n' => $ovBusy]) ?></span>
    <?php endif; ?>
    <?php if ($ovErr > 0): ?>
        <span class="kb-overview-item" data-tone="error"><?= te('{n} en échec', ['n' => $ovErr]) ?></span>
    <?php endif; ?>
    <?php if ($ovWarn > 0): ?>
        <span class="kb-overview-item" data-tone="warn"><?= te('{n} à vérifier', ['n' => $ovWarn]) ?></span>
    <?php endif; ?>
</span>
