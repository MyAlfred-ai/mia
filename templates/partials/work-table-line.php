<?php
/**
 * Tables nommées SOUS une réponse — une ligne discrète par table (nom, nombre
 * de lignes) et un bouton « Voir ». Jamais la grille : une table ne s'ouvre
 * qu'à la demande, dans le volet de droite (work-tables.js). Une table
 * PERMANENTE que la réponse a lue ou proposé de modifier s'y nomme aussi
 * (référence « p12 », mention « table permanente »).
 *
 * Hors de `.msg-content` : ni copiée, ni exportée avec la réponse.
 *
 * Rendu au chargement de la page (message.php) ET renvoyé tout fait par le
 * serveur (WorkTableView::lineHtml : fin d'un tour, historique JSON, bilan
 * d'une opération de fond) — un seul gabarit, aucun libellé dans le script.
 *
 * Une vue ou un résultat tiré d'une table permanente nomme celle-ci d'abord
 * (`parent`) : « Clients 2026 › Clients à relancer ».
 *
 * @var array{items: list<array{id:int|string,label:string,parent?:string,meta:string,permanent:bool}>, more:int, more_label:string} $tableLine
 */
?>
<div class="msg-tables">
    <?php foreach ($tableLine['items'] as $tableItem): ?>
    <button type="button" class="msg-table-chip<?= !empty($tableItem['permanent']) ? ' is-permanent' : '' ?>" data-table-open="<?= htmlspecialchars((string)$tableItem['id']) ?>" title="<?= te('Ouvrir la table dans le volet') ?>">
        <span class="msg-table-ico" aria-hidden="true"><?= WorkTableView::icon(14) ?></span>
        <?php if (($tableItem['parent'] ?? '') !== ''): ?>
        <span class="msg-table-parent"><?= htmlspecialchars((string)$tableItem['parent']) ?></span><span class="msg-table-sep" aria-hidden="true">›</span>
        <?php endif; ?>
        <span class="msg-table-name"><?= htmlspecialchars($tableItem['label']) ?></span>
        <span class="msg-table-meta"><?= htmlspecialchars($tableItem['meta']) ?></span>
        <span class="msg-table-see"><?= te('Voir', [], 'bouton') ?></span>
    </button>
    <?php endforeach; ?>
    <?php if ($tableLine['more'] > 0): ?>
    <button type="button" class="msg-table-more" data-table-open="0"><?= htmlspecialchars($tableLine['more_label']) ?></button>
    <?php endif; ?>
</div>
