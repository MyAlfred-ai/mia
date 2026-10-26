<?php
/**
 * État du modèle PowerPoint de l'entreprise (Organisation → Entreprise).
 * Rendu au chargement de la page ET renvoyé tout fait après un dépôt
 * (AdminController::saveCompany, clé `company_template_html`) : le
 * JavaScript remplace le bloc sans connaître un seul libellé.
 *
 * Variables : $templateInfo (array|null, CompanyProfile::templateInfo()),
 *             $templateError (string, refus du dernier dépôt, '' sinon).
 *
 * Miniature : celle que PowerPoint enregistre dans le fichier ; à défaut, un
 * aperçu INDICATIF dessiné avec les couleurs et les polices du thème.
 *
 * Le tableau « Comment MIA remplit votre modèle » dit, pour chaque forme que
 * MIA écrit, la mise en page du modèle qu'elle prend — la MÊME
 * correspondance que PptxTemplate::fill() (titre → cover, partie → section,
 * deux colonnes → two, comparaison → comparison, chiffres / tableau / étapes /
 * chronologie → titleOnly, le reste → content). Sans lui, l'administrateur
 * déposait un modèle sans savoir ce que MIA en ferait.
 *
 * Libellés de rôle (PptxTemplate::ROLE_LABELS) : clés de traduction, passées
 * à t() à l'affichage. Le nom d'une mise en page vient du fichier : jamais
 * traduit.
 */
$templateInfo  = $templateInfo ?? null;
$templateError = (string)($templateError ?? '');
$roleLabels    = PptxTemplate::ROLE_LABELS;
// Ce que MIA écrit (Markdown de la conversation) → rôle de mise en page du modèle.
$fillRows = [
    ['role' => 'cover',      'label' => t('Couverture'),                             'md' => t('# Titre'),                   'mini' => 'cover'],
    ['role' => 'section',    'label' => t('Partie'),                                 'md' => t('## seul, sans contenu'),     'mini' => 'section'],
    ['role' => 'content',    'label' => t('Puces, points clés, citation'),           'md' => t('## Titre puis - puces'),     'mini' => 'content'],
    ['role' => 'two',        'label' => t('Deux colonnes'),                          'md' => t('beaucoup de puces courtes'), 'mini' => 'two'],
    ['role' => 'comparison', 'label' => t('Comparaison'),                            'md' => t('deux ### et leurs puces'),   'mini' => 'comparison'],
    ['role' => 'titleOnly',  'label' => t('Chiffres, tableau, étapes, chronologie'), 'md' => t('formes dessinées par MIA'),  'mini' => 'shapes',
     'note' => t('formes aux couleurs de votre modèle')],
];
$hex = static fn ($v, string $fallback): string => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) === 1 ? $v : $fallback;
?>
<div class="company-template-status" id="companyTemplateStatus" aria-live="polite">
    <?php if ($templateError !== ''): ?>
        <p class="company-template-error" role="alert"><?= te('Modèle refusé : {raison}', ['raison' => $templateError]) ?></p>
    <?php endif; ?>

    <?php if ($templateInfo === null): ?>
        <p class="brand-hint company-template-empty">
            <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/settings">', '</a>'],
                te("Aucun modèle : les présentations suivent le thème de MIA, à la couleur d'accentuation de {a}Marque & apparence{/a}, avec le logo, le nom de l'entreprise et sa mention de pied de page. Déposez le modèle officiel pour que MIA remplisse ses mises en page à la place.")) ?>
        </p>
    <?php else:
        $size  = (int)($templateInfo['size'] ?? 0);
        $date  = (string)($templateInfo['uploaded_at'] ?? '');
        $roles = is_array($templateInfo['roles'] ?? null) ? $templateInfo['roles'] : [];
        $fonts = is_array($templateInfo['fonts'] ?? null) ? $templateInfo['fonts'] : [];
        $colors = is_array($templateInfo['colors'] ?? null) ? $templateInfo['colors'] : [];
        $examples = (int)($templateInfo['slides'] ?? 0);
        $thumb = CompanyProfile::templateThumbnail('pptx');
        $tplPath = CompanyProfile::templatePath();
        $ratio = (string)($templateInfo['ratio'] ?? '16:9');
        $layoutCount = (int)($templateInfo['layouts'] ?? 0);
    ?>
        <div class="company-template-body">
            <figure class="company-template-figure">
                <?php if ($thumb !== null): ?>
                    <img class="company-template-thumb" src="/admin/company/thumbnail/pptx?v=<?= (int)@filemtime((string)$tplPath) ?>"
                         alt="<?= te('Première slide du modèle PowerPoint') ?>">
                    <figcaption><?= te('Miniature enregistrée dans le modèle') ?></figcaption>
                <?php else: ?>
                    <!-- Aperçu indicatif : polices et couleurs du thème, pas les masques. -->
                    <div class="company-template-mock is-slide<?= $ratio === '4:3' ? ' is-4-3' : '' ?>" aria-hidden="true"
                         style="--mk-bg:<?= htmlspecialchars($hex($colors['lt1'] ?? null, '#ffffff')) ?>;--mk-text:<?= htmlspecialchars($hex($colors['dk2'] ?? ($colors['dk1'] ?? null), '#1f2937')) ?>;--mk-accent:<?= htmlspecialchars($hex($colors['accent1'] ?? null, '#10a37f')) ?>;--mk-accent2:<?= htmlspecialchars($hex($colors['accent2'] ?? null, '#94a3b8')) ?>">
                        <span class="mk-bar"></span>
                        <span class="mk-title" style="font-family:'<?= htmlspecialchars(str_replace("'", '', (string)($fonts['major'] ?? ''))) ?>', sans-serif"><?= te('Titre de la présentation') ?></span>
                        <span class="mk-sub" style="font-family:'<?= htmlspecialchars(str_replace("'", '', (string)($fonts['minor'] ?? ''))) ?>', sans-serif"><?= te('Sous-titre') ?></span>
                        <span class="mk-dots"><i></i><i></i><i></i></span>
                    </div>
                    <figcaption><?= te('Aperçu indicatif (couleurs et polices du thème) : le modèle ne porte pas de miniature.') ?></figcaption>
                <?php endif; ?>
            </figure>

            <div class="company-template-details">
                <div class="company-template-file">
                    <span class="company-template-badge" aria-hidden="true">PPTX</span>
                    <div class="company-template-meta">
                        <strong><?= htmlspecialchars((string)($templateInfo['name'] ?? t('Modèle PowerPoint'))) ?></strong>
                        <span class="brand-hint">
                            <?= te('format {ratio}', ['ratio' => $ratio]) ?>
                            <?php if ($layoutCount > 0): ?> · <?= htmlspecialchars(I18n::plural($layoutCount, '{n} mise en page', '{n} mises en page')) ?><?php endif; ?>
                            <?php if ($size > 0): ?> · <?= htmlspecialchars(Format::sizeMo($size)) ?><?php endif; ?>
                            <?php if ($date !== ''): ?> · <?= te('déposé le {date}', ['date' => Format::dateFr($date)]) ?><?php endif; ?>
                        </span>
                    </div>
                    <span class="company-template-chip"><?= te('Utilisé pour vos présentations') ?></span>
                </div>

                <p class="company-template-usage">
                    <?= te("Les présentations PowerPoint que MIA livre dans la conversation utilisent ce modèle : ses mises en page, ses polices, ses couleurs, ses arrière-plans et les éléments de son masque. La « Version soignée » réalisée par un modèle s'en sert comme référence du style maison ; « Version soignée › Par Gamma » garde le thème choisi dans Gamma.") ?>
                </p>

                <?php if ($colors !== [] || $fonts !== []): ?>
                    <div class="company-template-theme">
                        <?php foreach (['accent1', 'accent2', 'accent3', 'accent4', 'dk1', 'lt1', 'dk2', 'lt2'] as $name):
                            $c = (string)($colors[$name] ?? '');
                            if (preg_match('/^#[0-9a-f]{6}$/i', $c) !== 1) { continue; }
                        ?>
                            <span class="company-template-swatch" style="background:<?= htmlspecialchars($c) ?>" title="<?= htmlspecialchars($name . ' ' . $c) ?>"></span>
                        <?php endforeach; ?>
                        <?php if (($fonts['major'] ?? '') !== '' || ($fonts['minor'] ?? '') !== ''): ?>
                            <span class="brand-hint">
                                <?= te('Polices : {police}', ['police' => (string)($fonts['major'] ?? '')]) ?><?php if (($fonts['minor'] ?? '') !== '' && ($fonts['minor'] ?? '') !== ($fonts['major'] ?? '')): ?> / <?= htmlspecialchars((string)$fonts['minor']) ?><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($examples > 0): ?>
                    <p class="brand-hint">
                        <?= htmlspecialchars(I18n::plural($examples, 'La slide d\'exemple du modèle n\'est pas reprise : seuls ses masques, mises en page, polices et couleurs servent.', 'Les {n} slides d\'exemple du modèle ne sont pas reprises : seuls ses masques, mises en page, polices et couleurs servent.')) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($roles !== []): ?>
            <div class="company-template-map">
                <h4><?= te('Comment MIA remplit votre modèle') ?></h4>
                <p class="brand-hint"><?= te('Chaque slide que MIA écrit prend la mise en page correspondante de votre modèle. Ce tableau se met à jour à chaque dépôt.') ?></p>
                <div class="ctm-table" role="table" aria-label="<?= te('Correspondance entre les slides de MIA et les mises en page du modèle') ?>">
                    <div class="ctm-row ctm-head" role="row">
                        <span role="columnheader"><?= te('Ce que MIA écrit') ?></span>
                        <span aria-hidden="true"></span>
                        <span role="columnheader"><?= te('Mise en page de votre modèle') ?></span>
                        <span role="columnheader"><?= te('État') ?></span>
                    </div>
                    <?php foreach ($fillRows as $row):
                        $entry = $roles[$row['role']] ?? null;
                        if (!is_array($entry)) { continue; }
                        $fallback = !empty($entry['fallback']);
                    ?>
                        <div class="ctm-row" role="row">
                            <div class="ctm-mia" role="cell">
                                <span class="ctm-mini is-<?= htmlspecialchars($row['mini']) ?>" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                                <span class="ctm-what">
                                    <strong><?= htmlspecialchars($row['label']) ?></strong>
                                    <code><?= htmlspecialchars($row['md']) ?></code>
                                </span>
                            </div>
                            <span class="ctm-arrow" aria-hidden="true">→</span>
                            <div class="ctm-layout" role="cell">
                                <strong><?= te('« {label} »', ['label' => (string)($entry['name'] ?? '')]) ?></strong>
                                <?php if ($fallback): ?>
                                    <span class="brand-hint"><?= te('pas de mise en page « {role} » déclarée : MIA prend la plus proche', ['role' => t($roleLabels[$row['role']] ?? '')]) ?></span>
                                <?php elseif (isset($row['note'])): ?>
                                    <span class="brand-hint"><?= htmlspecialchars($row['note']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div role="cell">
                                <span class="company-template-state <?= $fallback ? 'is-fallback' : 'is-found' ?>"><?= $fallback ? te('À défaut') : te('Trouvée') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="company-template-split">
                <div>
                    <h4><?= te('Ce qui vient de votre modèle') ?></h4>
                    <ul class="ctm-pills">
                        <li><?= te('Polices') ?></li><li><?= te('Couleurs du thème') ?></li><li><?= te('Arrière-plans') ?></li>
                        <li><?= te('Logo et éléments du masque') ?></li><li><?= te('Mises en page') ?></li>
                    </ul>
                </div>
                <div>
                    <h4><?= te('Ce que MIA ajoute') ?></h4>
                    <ul class="ctm-pills">
                        <li><?= te('Le texte de la présentation') ?></li><li><?= te('Chiffres, tableaux, étapes, chronologies') ?></li>
                        <li><?= te('Pied de page et numéro de slide') ?></li>
                    </ul>
                </div>
            </div>
            <p class="brand-hint"><?= te("Le PDF d'une présentation garde la mise en page de MIA, aux couleurs de votre modèle.") ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
