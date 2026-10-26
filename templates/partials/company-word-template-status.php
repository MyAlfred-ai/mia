<?php
/**
 * État du modèle Word de l'entreprise (Organisation → Entreprise). Rendu au
 * chargement de la page ET renvoyé tout fait après un dépôt
 * (AdminController::saveCompany, clé `company_word_template_html`).
 *
 * Variables : $wordTemplateInfo (array|null, CompanyProfile::wordTemplateInfo()),
 *             $wordTemplateError (string, refus du dernier dépôt, '' sinon).
 *
 * Format de page : recalculé depuis `page_size` dans la langue de qui lit ;
 * une fiche d'avant n'a que `page`, libellé figé dans la langue du dépôt.
 */
$wordTemplateInfo  = $wordTemplateInfo ?? null;
$wordTemplateError = (string)($wordTemplateError ?? '');
$hex = static fn ($v, string $fallback): string => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) === 1 ? $v : $fallback;
?>
<div class="company-template-status" id="companyWordTemplateStatus" aria-live="polite">
    <?php if ($wordTemplateError !== ''): ?>
        <p class="company-template-error" role="alert"><?= te('Modèle refusé : {raison}', ['raison' => $wordTemplateError]) ?></p>
    <?php endif; ?>

    <?php if ($wordTemplateInfo === null): ?>
        <p class="brand-hint company-template-empty">
            <?= te("Aucun modèle : les documents suivent la mise en page de MIA (A4, Calibri), avec le logo de l'entreprise en en-tête et sa mention de pied de page.") ?>
        </p>
    <?php else:
        $size    = (int)($wordTemplateInfo['size'] ?? 0);
        $date    = (string)($wordTemplateInfo['uploaded_at'] ?? '');
        $styles  = is_array($wordTemplateInfo['styles'] ?? null) ? $wordTemplateInfo['styles'] : [];
        $fonts   = is_array($wordTemplateInfo['fonts'] ?? null) ? $wordTemplateInfo['fonts'] : [];
        $colors  = is_array($wordTemplateInfo['colors'] ?? null) ? $wordTemplateInfo['colors'] : [];
        $content = (int)($wordTemplateInfo['content'] ?? 0);
        $headers = (int)($wordTemplateInfo['headers'] ?? 0);
        $footers = (int)($wordTemplateInfo['footers'] ?? 0);
        $thumb   = CompanyProfile::templateThumbnail('docx');
        $tplPath = CompanyProfile::wordTemplatePath();
        $pageSize  = is_array($wordTemplateInfo['page_size'] ?? null) ? $wordTemplateInfo['page_size'] : null;
        $pageLabel = $pageSize !== null ? DocxTemplate::pageLabel($pageSize) : (string)($wordTemplateInfo['page'] ?? '');
        $landscape = $pageSize !== null
            ? ($pageSize['orient'] ?? '') === 'landscape'
            : str_contains((string)($wordTemplateInfo['page'] ?? ''), 'paysage');
    ?>
        <div class="company-template-body">
            <figure class="company-template-figure is-page">
                <?php if ($thumb !== null): ?>
                    <img class="company-template-thumb" src="/admin/company/thumbnail/docx?v=<?= (int)@filemtime((string)$tplPath) ?>"
                         alt="<?= te('Première page du modèle Word') ?>">
                    <figcaption><?= te('Miniature enregistrée dans le modèle') ?></figcaption>
                <?php else: ?>
                    <!-- Aperçu indicatif : polices et couleurs du thème, pas la mise en page. -->
                    <div class="company-template-mock is-page<?= $landscape ? ' is-landscape' : '' ?>" aria-hidden="true"
                         style="--mk-bg:#ffffff;--mk-text:<?= htmlspecialchars($hex($colors['dk2'] ?? ($colors['dk1'] ?? null), '#1f2937')) ?>;--mk-accent:<?= htmlspecialchars($hex($colors['accent1'] ?? null, '#1f3864')) ?>;--mk-accent2:<?= htmlspecialchars($hex($colors['accent2'] ?? null, '#94a3b8')) ?>">
                        <span class="mk-title" style="font-family:'<?= htmlspecialchars(str_replace("'", '', (string)($fonts['major'] ?? ''))) ?>', serif"><?= te('Titre du document') ?></span>
                        <span class="mk-lines"><i></i><i></i><i></i><i class="short"></i></span>
                        <span class="mk-h2" style="font-family:'<?= htmlspecialchars(str_replace("'", '', (string)($fonts['major'] ?? ''))) ?>', serif"><?= te('Titre 1') ?></span>
                        <span class="mk-lines"><i></i><i></i><i class="short"></i></span>
                    </div>
                    <figcaption><?= te('Aperçu indicatif (couleurs et polices du thème) : le modèle ne porte pas de miniature.') ?></figcaption>
                <?php endif; ?>
            </figure>

            <div class="company-template-details">
                <div class="company-template-file">
                    <span class="company-template-badge is-word" aria-hidden="true">DOCX</span>
                    <div class="company-template-meta">
                        <strong><?= htmlspecialchars((string)($wordTemplateInfo['name'] ?? t('Modèle Word'))) ?></strong>
                        <span class="brand-hint">
                            <?= htmlspecialchars($pageLabel) ?>
                            <?php if ($size > 0): ?> · <?= htmlspecialchars(Format::sizeMo($size)) ?><?php endif; ?>
                            <?php if ($date !== ''): ?> · <?= te('déposé le {date}', ['date' => Format::dateFr($date)]) ?><?php endif; ?>
                        </span>
                    </div>
                </div>

                <dl class="company-template-roles">
                    <div>
                        <dt><?= te('Titres') ?></dt>
                        <dd>
                            <?php
                            $found = [];
                            $missing = [];
                            foreach (DocxTemplate::HEADING_LABELS as $name => $label) {
                                // Libellés de Word (clés de traduction : « Titre 1 » → « Heading 1 »).
                                if (!empty($styles[$name])) { $found[] = t($label); } else { $missing[] = t($label); }
                            }
                            ?>
                            <?= htmlspecialchars($found !== [] ? implode(', ', $found) : t('aucun style de titre')) ?>
                            <?php if ($missing !== []): ?>
                                <span class="company-template-fallback" title="<?= te("Le modèle n'a pas ces styles : MIA ajoute les siens.") ?>">
                                    <?= te('{styles} : ceux de MIA', ['styles' => implode(', ', $missing)]) ?>
                                </span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt><?= te('En-tête') ?></dt>
                        <dd><?= $headers > 0 ? (!empty($wordTemplateInfo['header_image']) ? te('Celui du modèle, avec son image') : te('Celui du modèle')) : te('Aucun') ?></dd>
                    </div>
                    <div>
                        <dt><?= te('Pied de page') ?></dt>
                        <dd><?= $footers > 0 ? te('Celui du modèle') : te('Celui de MIA : mention de l\'entreprise et numéro de page') ?></dd>
                    </div>
                </dl>

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

                <?php if ($content > 0): ?>
                    <p class="brand-hint">
                        <?= htmlspecialchars(I18n::plural($content, 'Le paragraphe d\'exemple du modèle n\'est pas repris : seuls ses styles, sa mise en page, ses en-têtes et ses pieds de page servent.', 'Les {n} paragraphes d\'exemple du modèle ne sont pas repris : seuls ses styles, sa mise en page, ses en-têtes et ses pieds de page servent.')) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
