<?php
/**
 * État d'une base de connaissances — SEUL rendu, pour deux emplacements :
 *   - `card`  : zone vivante de la carte (état, barre, légende, volumétrie) ;
 *   - `panel` : panneau d'état en tête de la fiche de la base (même verdict, détaillé).
 *
 * Rendu au chargement de la page ET renvoyé tout fait par les points de
 * rafraîchissement (`GET /admin/knowledge-bases/status`, `…/{id}/sync-status`) :
 * le navigateur ne fait que remplacer le bloc. Aucun libellé n'existe donc en
 * JavaScript — deux rendus finiraient par se contredire au premier ajustement.
 * Libellés traduits (te/t) : le bloc suit la locale de la requête qui le rend.
 *
 * Attend : $kb (ligne knowledge_bases), $kbStatus (KbStatus::summarize),
 * $kbStatusVariant ('card'|'panel'), $kbCanManage (bool) ; en panneau,
 * $kbOwnerName (porteur de la connexion, '' sinon).
 */
$st      = $kbStatus;
$variant = $kbStatusVariant ?? 'card';
$kbIdSt  = (int)$kb['id'];
$c       = $st['counts'];
$tone    = (string)$st['tone'];
$state   = (string)$st['state'];

$icon = match (true) {
    $state === KbStatus::QUEUED || $state === KbStatus::WAITING
        => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    $tone === KbStatus::TONE_BUSY
        => '<span class="kb-spin"></span>',
    $tone === KbStatus::TONE_OK
        => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>',
    $tone === KbStatus::TONE_WARN
        => '<svg viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
    $tone === KbStatus::TONE_ERROR
        => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/></svg>',
    default
        => '<svg viewBox="0 0 24 24"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1Z"/></svg>',
};

// Relancer n'a de sens que hors passage en cours, sur une base qui en a
// les droits — et le libellé suit la situation.
$canSync   = !empty($kbCanManage) && !empty($st['syncable']) && empty($st['busy']);
$syncLabel = match ($state) {
    KbStatus::ERROR   => t('Réessayer'),
    KbStatus::STALLED => t('Relancer'),
    default           => t('Synchroniser maintenant'),
};

// Barre : progression d'un passage vivant, sinon santé des documents — mais
// seulement quand elle informe. Sous « en attente » ou « en échec », une barre
// verte (les documents d'avant, toujours servis) brouillerait le message
// principal : le panneau de la fiche le dit en toutes lettres.
$p          = $st['progress'];
$showHealth = in_array($state, [KbStatus::OK, KbStatus::PARTIAL, KbStatus::LATE, KbStatus::INDEXING], true);
$segments   = [];
if ($p === null && $showHealth && $c['total'] > 0) {
    foreach ([
        'ok'    => $c['indexed'],
        'warn'  => $c['no_text'],
        'error' => $c['failed'] + $c['lexical'],
        'busy'  => $c['busy'] + $c['pending'],
    ] as $segTone => $n) {
        if ($n > 0) {
            $segments[] = ['tone' => $segTone, 'n' => $n, 'pct' => round($n * 100 / $c['total'], 2)];
        }
    }
}

// Légende : seulement quand tout n'est pas indexé — « 38 indexés » répété sous
// « 38 documents indexés » n'apprendrait rien.
$legend = [];
if ($showHealth && $c['total'] > 0 && $c['indexed'] < $c['total']) {
    $legIndexed = $c['indexed'];
    $legBusy    = $c['busy'] + $c['pending'];
    $legFailed  = $c['failed'] + $c['lexical'];
    $legNoText  = $c['no_text'];
    foreach ([
        ['ok',    $legIndexed, I18n::plural($legIndexed, '{n} indexé', '{n} indexés')],
        ['busy',  $legBusy,    t('{n} en cours', ['n' => $legBusy])],
        ['error', $legFailed,  t('{n} en échec', ['n' => $legFailed])],
        ['warn',  $legNoText,  t('{n} sans texte', ['n' => $legNoText])],
    ] as [$legTone, $n, $legText]) {
        if ($n > 0) {
            $legend[] = ['tone' => $legTone, 'text' => $legText];
        }
    }
}
if ($p !== null) {
    $barLabel = $p['label'] !== '' ? t('Progression : {progression}', ['progression' => $p['label']]) : t('Progression en cours');
} else {
    $barLabel = $legend !== []
        ? implode(', ', array_column($legend, 'text'))
        : I18n::plural((int)$c['indexed'], '{n} document indexé', '{n} documents indexés');
}

$renderBar = static function () use ($p, $segments, $barLabel): void {
    if ($p !== null) {
        $indeterminate = !empty($p['indeterminate']);
        ?>
        <div class="kb-bar is-progress<?= $indeterminate ? ' is-indeterminate' : '' ?>" role="progressbar"
             aria-label="<?= htmlspecialchars($barLabel) ?>"
             <?= $indeterminate ? '' : 'aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int)$p['pct'] . '"' ?>>
            <span class="kb-bar-fill" style="width:<?= $indeterminate ? 35 : (int)$p['pct'] ?>%"></span>
        </div>
        <?php
        return;
    }
    if ($segments === []) {
        return;
    }
    ?>
    <div class="kb-bar" role="img" aria-label="<?= htmlspecialchars($barLabel) ?>">
        <?php foreach ($segments as $seg): ?>
            <span class="kb-bar-seg" data-tone="<?= $seg['tone'] ?>" style="width:<?= $seg['pct'] ?>%"></span>
        <?php endforeach; ?>
    </div>
    <?php
};

$renderLegend = static function () use ($legend): void {
    if ($legend === []) {
        return;
    }
    ?>
    <div class="kb-legend">
        <?php foreach ($legend as $item): ?>
            <span class="kb-legend-item" data-tone="<?= $item['tone'] ?>"><?= htmlspecialchars($item['text']) ?></span>
        <?php endforeach; ?>
    </div>
    <?php
};
?>
<?php if ($variant === 'panel'): ?>
<?php
    // Dans la fiche, la base EST ouverte : l'invitation de la carte (« ouvrez
    // la base… ») y dirait l'inverse de ce qu'on voit.
    $panelDetail = $state === KbStatus::PARTIAL
        ? t('Quels documents, et pourquoi : voir « Documents de la base », plus bas — ils y sont listés en premier.')
        : $st['detail'];
?>
<div class="kb-panel" data-tone="<?= $tone ?>" data-state="<?= $state ?>" data-busy="<?= empty($st['busy']) ? 0 : 1 ?>">
    <div class="kb-panel-head">
        <span class="kb-status-ico" aria-hidden="true"><?= $icon ?></span>
        <div class="kb-panel-titles">
            <div class="kb-panel-title"><?= htmlspecialchars($st['title']) ?></div>
            <?php if ($panelDetail !== ''): ?>
                <div class="kb-panel-detail"><?= htmlspecialchars($panelDetail) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($st['meta'] !== ''): ?>
            <span class="kb-status-meta"><?= htmlspecialchars($st['meta']) ?></span>
        <?php endif; ?>
    </div>

    <?php $renderBar(); ?>
    <?php $renderLegend(); ?>

    <?php if (!empty($st['remote'])): ?>
        <?php /* Base en direct (MyBrain) : ni documents ni passages, seulement la
                 connexion — ce que MyBrain a répondu au dernier test. */ ?>
        <dl class="kb-panel-facts">
            <div>
                <dt><?= te('Source') ?></dt>
                <dd>MyBrain<?= $st['remote_host'] !== '' ? ' — ' . htmlspecialchars((string)$st['remote_host']) : '' ?>
                    <span class="kb-panel-muted">· <?= te('interrogé en direct, rien n\'est copié dans MIA') ?></span></dd>
            </div>
            <?php if ($st['remote_app'] !== ''): ?>
                <div>
                    <dt><?= te('Application') ?></dt>
                    <dd><?= te('« {label} »', ['label' => (string)$st['remote_app']]) ?>
                        <span class="kb-panel-muted">· <?= te('le nom de la clé dans MyBrain') ?></span></dd>
                </div>
            <?php endif; ?>
            <?php if ($st['remote_rights'] !== []): ?>
                <div>
                    <dt><?= te('Droits') ?></dt>
                    <dd><?= htmlspecialchars(implode(', ', (array)$st['remote_rights'])) ?></dd>
                </div>
            <?php endif; ?>
            <?php if ($st['meta'] !== ''): ?>
                <div>
                    <dt><?= te('Dernier contact') ?></dt>
                    <dd><?= htmlspecialchars(ucfirst((string)$st['meta'])) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    <?php elseif (!empty($st['synced']) || $c['total'] > 0): ?>
        <dl class="kb-panel-facts">
            <?php if (!empty($st['synced'])): ?>
                <?php /* Nom du fournisseur (KbSync::providerLabel) traduit ici :
                         « Google Agenda » → « Google Calendar ». */ ?>
                <?php $srcLabel = t(KbSync::providerLabel((string)$st['provider'])); ?>
                <div>
                    <dt><?= te('Source') ?></dt>
                    <dd>
                        <?= htmlspecialchars($srcLabel) ?><?= $st['folder'] !== '' ? ' — ' . te('« {label} »', ['label' => (string)$st['folder']]) : '' ?>
                        <?php if (($kbOwnerName ?? '') !== ''): ?>
                            <span class="kb-panel-muted">· <?= te('via la connexion de {nom}', ['nom' => (string)$kbOwnerName]) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if ($st['report_line'] !== ''): ?>
                    <div>
                        <dt><?= te('Dernier passage') ?></dt>
                        <dd>
                            <?= htmlspecialchars(ucfirst($st['report_line'])) ?>
                            <?php if ($st['last'] !== ''): ?><span class="kb-panel-muted">· <?= htmlspecialchars($st['last']) ?></span><?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php /* Pendant un passage (ou avant le premier), « le prochain
                         dans 9 min » ne voudrait rien dire. */ ?>
                <?php if (!in_array($state, [KbStatus::SYNCING, KbStatus::QUEUED, KbStatus::WAITING], true)): ?>
                    <div>
                        <dt><?= te('Prochain passage') ?></dt>
                        <dd>
                            <?php if (!empty($st['enabled'])): ?>
                                <?= $st['next'] !== '' ? htmlspecialchars(ucfirst($st['next'])) : te('Dès l\'enregistrement') ?>
                                <span class="kb-panel-muted">· <?= te('toutes les {n} min', ['n' => (int)$st['interval']]) ?></span>
                            <?php else: ?>
                                <span class="kb-panel-muted"><?= te('Synchronisation automatique désactivée') ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div>
                <dt><?= te('Documents') ?></dt>
                <dd>
                    <?= $c['total'] === 0
                        ? te('aucun')
                        : htmlspecialchars(I18n::plural(
                            (int)$c['indexed'],
                            '{n} / {total} interrogeable par le sens',
                            '{n} / {total} interrogeables par le sens',
                            ['total' => (int)$c['total']]
                        )) ?>
                </dd>
            </div>
        </dl>
    <?php endif; ?>

    <?php if ($st['issues'] !== []): ?>
        <?php
            $report   = (array)$st['report'];
            $notTaken = (int)($report['ignored_unsupported'] ?? 0) + (int)($report['ignored_too_big'] ?? 0)
                      + (int)($report['ignored_over_cap'] ?? 0) + (int)($report['failed'] ?? 0);
            $notTaken = max($notTaken, count($st['issues']));
        ?>
        <details class="kb-panel-issues">
            <summary><?= htmlspecialchars(I18n::plural($notTaken, '{n} fichier de la source non repris', '{n} fichiers de la source non repris')) ?></summary>
            <ul>
                <?php /* Motif écrit en français dans le rapport (KbSync::noteIssue) : les
                         motifs fixes se traduisent ici ; celui d'un fournisseur est du
                         contenu, t() le rend tel quel. */ ?>
                <?php foreach ($st['issues'] as $issue): ?>
                    <li><span class="kb-panel-file"><?= htmlspecialchars((string)$issue['name']) ?></span> — <?= htmlspecialchars(t((string)($issue['reason'] ?? ''))) ?></li>
                <?php endforeach; ?>
                <?php if ($notTaken > count($st['issues'])): ?>
                    <li class="kb-panel-muted"><?= htmlspecialchars(I18n::plural($notTaken - count($st['issues']), '… et {n} autre', '… et {n} autres')) ?></li>
                <?php endif; ?>
            </ul>
        </details>
    <?php endif; ?>

    <?php if ($canSync): ?>
        <div class="kb-panel-actions">
            <button type="button" class="btn-secondary kb-panel-sync" data-kb-sync-now="<?= $kbIdSt ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-9-9c2.5 0 4.9 1 6.7 2.7L21 8"/><path d="M21 3v5h-5"/></svg>
                <?= htmlspecialchars($syncLabel) ?>
            </button>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="kb-live" data-kb-live="<?= $kbIdSt ?>">
    <div class="kb-status" data-tone="<?= $tone ?>" data-state="<?= $state ?>">
        <span class="kb-status-ico" aria-hidden="true"><?= $icon ?></span>
        <div class="kb-status-body">
            <div class="kb-status-line">
                <span class="kb-status-title"><?= htmlspecialchars($st['title']) ?></span>
                <?php if ($st['meta'] !== ''): ?>
                    <span class="kb-status-meta"><?= htmlspecialchars($st['meta']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($st['detail'] !== ''): ?>
                <div class="kb-status-detail" title="<?= htmlspecialchars($st['detail']) ?>"><?= htmlspecialchars($st['detail']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($canSync): ?>
            <button type="button" class="kb-status-act" data-kb-sync-now="<?= $kbIdSt ?>"
                    title="<?= htmlspecialchars($syncLabel) ?>" aria-label="<?= htmlspecialchars($syncLabel) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-9-9c2.5 0 4.9 1 6.7 2.7L21 8"/><path d="M21 3v5h-5"/></svg>
            </button>
        <?php endif; ?>
    </div>

    <?php $renderBar(); ?>
    <?php $renderLegend(); ?>

    <?php
        $docCountSt   = (int)($kb['doc_count'] ?? $c['total']);
        $chunkCountSt = (int)($kb['chunk_count'] ?? 0);
        $usageCountSt = (int)($kb['query_count'] ?? 0);
        $usageLastSt  = (string)($kb['last_used_at'] ?? '');
        // Compte en gras dans une phrase traduite : `{b}{n}{/b} extraits` — le
        // texte est échappé d'abord, seules NOS balises passent (cf. teb()).
        $boldCount = static fn (string $text): string => str_replace(
            ['{b}', '{/b}'],
            ['<strong>', '</strong>'],
            htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        );
    ?>
    <?php if (!empty($st['remote'])): ?>
    <div class="kb-meta">
        <span class="kb-meta-item" title="<?= te('Cerveau interrogé à chaque message — rien n\'est copié dans MIA') ?>">
            <strong>MyBrain</strong><?= $st['remote_host'] !== '' ? ' ' . htmlspecialchars((string)$st['remote_host']) : '' ?>
        </span>
        <?php if ($st['remote_rights'] !== []): ?>
            <span class="kb-meta-item" title="<?= te('Droits de la clé, lus dans MyBrain au dernier test') ?>">
                <?= htmlspecialchars(implode(' · ', (array)$st['remote_rights'])) ?>
            </span>
        <?php endif; ?>
        <?php if ($usageCountSt > 0): ?>
            <span class="kb-meta-item kb-meta-use"
                  title="<?= $usageLastSt !== ''
                      ? te('Réponses dans lesquelles le cerveau a réellement rendu des fiches — dernière fois : {date}', ['date' => $usageLastSt])
                      : te('Réponses dans lesquelles le cerveau a réellement rendu des fiches') ?>">
                <?= $boldCount(I18n::plural($usageCountSt, '{b}{n}{/b} utilisation', '{b}{n}{/b} utilisations')) ?>
            </span>
        <?php else: ?>
            <span class="kb-meta-item kb-meta-unused"
                  title="<?= te('Aucune réponse n\'a encore puisé dans le cerveau : il n\'est coché dans aucune conversation, ou lié à aucun assistant.') ?>">
                <?= te('jamais utilisée') ?>
            </span>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="kb-meta">
        <span class="kb-meta-item" title="<?= te('Documents dans la base') ?>"><?= $boldCount(I18n::plural($docCountSt, '{b}{n}{/b} doc', '{b}{n}{/b} docs')) ?></span>
        <span class="kb-meta-item" title="<?= te('Extraits vectorisés interrogeables (chunks)') ?>"><?= $boldCount(I18n::plural($chunkCountSt, '{b}{n}{/b} extrait', '{b}{n}{/b} extraits')) ?></span>
        <span class="kb-meta-item" title="<?= te('Taille cumulée des fichiers') ?>"><strong><?= Format::sizeMo((int)($kb['total_size'] ?? 0)) ?></strong></span>
        <?php if ($usageCountSt > 0): ?>
            <span class="kb-meta-item kb-meta-use"
                  title="<?= $usageLastSt !== ''
                      ? te('Réponses dans lesquelles cette base a réellement injecté des extraits — dernière fois : {date}', ['date' => $usageLastSt])
                      : te('Réponses dans lesquelles cette base a réellement injecté des extraits') ?>">
                <?= $boldCount(I18n::plural($usageCountSt, '{b}{n}{/b} utilisation', '{b}{n}{/b} utilisations')) ?>
            </span>
        <?php else: ?>
            <span class="kb-meta-item kb-meta-unused"
                  title="<?= te('Aucune réponse n\'a encore puisé dans cette base : soit elle n\'est liée à aucun assistant, soit ses documents ne ressortent jamais des recherches.') ?>">
                <?= te('jamais utilisée') ?>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
