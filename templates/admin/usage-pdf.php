<?php
/**
 * Rapport PDF « utilisation des crédits » (rendu via PdfExportService/mPDF).
 * Contraintes mPDF : tables uniquement (pas de flex/grid), couleurs hex
 * littérales, pas de variables CSS. Variables fournies par
 * AdminController::usageExportPdf() : $view, $filters, $data, $filterUserLabel.
 *
 * @var string $view            'monthly' | 'users'
 * @var array  $filters         preset/from/to/userId
 * @var array  $data            kpis/monthly/usersTotals/monthlyPerUser/creditValueEur
 * @var string $filterUserLabel libellé du filtre utilisateur
 */

$kpis = $data['kpis'];
$periodLabel = ($filters['from'] !== null && $filters['to'] !== null)
    ? Format::dateFr($filters['from']) . ' – ' . Format::dateFr($filters['to'])
    : t("Tout l'historique");
// Valeur du crédit sans zéros traînants, séparateur décimal de la locale (« 0,001 » / « 0.001 »).
$pdfDec = I18n::locale() === 'en' ? '.' : ',';
$creditValueLabel = rtrim(rtrim(number_format($data['creditValueEur'], 6, $pdfDec, ''), '0'), $pdfDec);
$pdfPct = static fn(float $v): string => t('{n} %', ['n' => number_format($v, 1, $pdfDec, $pdfDec === '.' ? ',' : ' ')]);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: sans-serif; color: #1a1a1a; font-size: 10pt; }
    h1 { font-size: 16pt; margin: 0 0 4px 0; }
    h2 { font-size: 12pt; margin: 18px 0 8px 0; }
    .meta { color: #555; font-size: 9pt; margin: 0 0 14px 0; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #cccccc; padding: 6px 8px; font-size: 9.5pt; text-align: left; }
    th { background: #f2f2f2; font-weight: bold; }
    .num { text-align: right; }
    .total-row td { background: #f2f2f2; font-weight: bold; }
    .muted { color: #777; }
</style>
</head>
<body>

<h1><?= te("Rapport d'utilisation des crédits") ?></h1>
<p class="meta">
    <?= te('Période : {periode}', ['periode' => $periodLabel]) ?> ·
    <?= te('Utilisateur : {nom}', ['nom' => $filterUserLabel]) ?> ·
    <?= te('Généré le {date}', ['date' => Format::dateFr(date('Y-m-d')) . ' ' . date('H:i')]) ?><br>
    <?= teb('{b}Coût réel IA{/b} = tarifs fournisseurs (hors marge) sommés opération par opération') ?> ·
    <?= teb('{b}Valorisation{/b} = crédits × valeur actuelle du crédit ({valeur} € / crédit, configurable dans « Tarifs & crédits »).', ['valeur' => $creditValueLabel]) ?>
</p>

<table>
    <thead>
        <tr>
            <th class="num"><?= te('Crédits consommés') ?></th>
            <th class="num"><?= te('dont hors quota') ?></th>
            <th class="num"><?= te('Coût réel IA') ?></th>
            <th class="num"><?= te('Valorisation') ?></th>
            <th class="num"><?= te('Opérations') ?></th>
            <th class="num"><?= te('Utilisateurs actifs') ?></th>
            <th class="num"><?= te('Tokens') ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="num"><?= Format::credits((int)$kpis['total_credits']) ?></td>
            <td class="num"><?= Format::credits((int)($kpis['exempt_credits'] ?? 0)) ?></td>
            <td class="num"><?= Format::eur((float)$kpis['cost_eur']) ?></td>
            <td class="num"><?= Format::eur((float)$kpis['billed_eur']) ?></td>
            <td class="num"><?= Format::credits((int)$kpis['requests']) ?></td>
            <td class="num"><?= (int)$kpis['active_users'] ?></td>
            <td class="num"><?= Format::tokens((int)$kpis['tokens']) ?></td>
        </tr>
    </tbody>
</table>

<?php if ($view === 'monthly'): ?>
<?php $familyKeys = UsageSource::families(); ?>
<h2><?= te('Consommation mensuelle') ?></h2>
<table>
    <thead>
        <tr>
            <th><?= te('Mois') ?></th>
            <?php foreach ($familyKeys as $family): ?>
                <th class="num"><?= htmlspecialchars(UsageSource::familyLabel($family)) ?></th>
            <?php endforeach; ?>
            <th class="num"><?= te('Total crédits') ?></th>
            <th class="num"><?= te('Coût réel IA') ?></th>
            <th class="num"><?= te('Valorisation') ?></th>
            <th class="num"><?= te('Opérations') ?></th>
            <th class="num"><?= te('Utilisateurs actifs') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data['monthly'] as $monthRow): ?>
        <tr>
            <td><?= htmlspecialchars(Format::monthLabel($monthRow['month'])) ?></td>
            <?php foreach ($familyKeys as $family): ?>
                <td class="num"><?= Format::credits((int)($monthRow['families'][$family] ?? 0)) ?></td>
            <?php endforeach; ?>
            <td class="num"><?= Format::credits((int)$monthRow['total_credits']) ?></td>
            <td class="num"><?= Format::eur((float)$monthRow['cost_eur']) ?></td>
            <td class="num"><?= Format::eur((float)$monthRow['billed_eur']) ?></td>
            <td class="num"><?= Format::credits((int)$monthRow['requests']) ?></td>
            <td class="num"><?= (int)$monthRow['active_users'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($data['monthly'])): ?>
        <tr><td colspan="<?= 6 + count($familyKeys) ?>" class="muted"><?= te('Aucune donnée sur la période.') ?></td></tr>
        <?php endif; ?>
        <tr class="total-row">
            <td><?= te('TOTAL') ?></td>
            <?php foreach ($familyKeys as $family): ?>
                <td class="num"><?= Format::credits((int)($kpis['families'][$family] ?? 0)) ?></td>
            <?php endforeach; ?>
            <td class="num"><?= Format::credits((int)$kpis['total_credits']) ?></td>
            <td class="num"><?= Format::eur((float)$kpis['cost_eur']) ?></td>
            <td class="num"><?= Format::eur((float)$kpis['billed_eur']) ?></td>
            <td class="num"><?= Format::credits((int)$kpis['requests']) ?></td>
            <td class="num"><?= (int)$kpis['active_users'] ?></td>
        </tr>
    </tbody>
</table>

<?php else: ?>
<h2><?= te('Consommation par utilisateur') ?></h2>
<table>
    <thead>
        <tr>
            <th><?= te('Utilisateur') ?></th>
            <th><?= te('Email') ?></th>
            <th class="num"><?= te('Crédits') ?></th>
            <th class="num"><?= te('dont hors quota') ?></th>
            <th class="num"><?= te('Coût réel IA') ?></th>
            <th class="num"><?= te('Valorisation') ?></th>
            <th class="num"><?= te('% du total') ?></th>
            <th class="num"><?= te('Opérations') ?></th>
            <th><?= te('Dernière activité') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data['usersTotals'] as $userRow): ?>
        <tr>
            <td><?= htmlspecialchars($userRow['user_name']) ?></td>
            <td><?= htmlspecialchars($userRow['email']) ?></td>
            <td class="num"><?= Format::credits((int)$userRow['total_credits']) ?></td>
            <td class="num"><?= Format::credits((int)$userRow['exempt_credits']) ?></td>
            <td class="num"><?= Format::eur((float)$userRow['cost_eur']) ?></td>
            <td class="num"><?= Format::eur((float)$userRow['billed_eur']) ?></td>
            <td class="num"><?= htmlspecialchars($pdfPct((float)$userRow['share_pct'])) ?></td>
            <td class="num"><?= Format::credits((int)$userRow['requests']) ?></td>
            <td><?= htmlspecialchars(Format::dateFr($userRow['last_activity'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($data['usersTotals'])): ?>
        <tr><td colspan="9" class="muted"><?= te('Aucune donnée sur la période.') ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($filters['userId'] !== null): ?>
<?php $userMonths = $data['monthlyPerUser'][(string)$filters['userId']] ?? []; ?>
<h2><?= te('Détail mensuel — {nom}', ['nom' => $filterUserLabel]) ?></h2>
<table>
    <thead>
        <tr>
            <th><?= te('Mois') ?></th>
            <th class="num"><?= te('Crédits') ?></th>
            <th class="num"><?= te('dont hors quota') ?></th>
            <th class="num"><?= te('Coût réel IA') ?></th>
            <th class="num"><?= te('Valorisation') ?></th>
            <th class="num"><?= te('Opérations') ?></th>
            <th class="num"><?= te('Cumul crédits') ?></th>
            <th class="num"><?= te('Cumul valorisation') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($userMonths as $monthRow): ?>
        <tr>
            <td><?= htmlspecialchars(Format::monthLabel($monthRow['month'])) ?></td>
            <td class="num"><?= Format::credits((int)$monthRow['total_credits']) ?></td>
            <td class="num"><?= Format::credits((int)$monthRow['exempt_credits']) ?></td>
            <td class="num"><?= Format::eur((float)$monthRow['cost_eur']) ?></td>
            <td class="num"><?= Format::eur((float)$monthRow['billed_eur']) ?></td>
            <td class="num"><?= Format::credits((int)$monthRow['requests']) ?></td>
            <td class="num"><?= Format::credits((int)$monthRow['cumul_credits']) ?></td>
            <td class="num"><?= Format::eur((float)$monthRow['cumul_billed_eur']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($userMonths)): ?>
        <tr><td colspan="8" class="muted"><?= te('Aucun détail mensuel.') ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>
<?php endif; ?>

</body>
</html>
