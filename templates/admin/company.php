<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars($settings['app_name'] ?? 'MIA') ?> — <?= te('Entreprise') ?></title>
    <link rel="icon" href="<?= htmlspecialchars($settings['logo_url'] ?? '/assets/img/logo.png') ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'company'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
// L'entreprise qui utilise MIA — distincte de la marque de l'application
// (nom, logos, couleurs : Marque & apparence). Source unique : CompanyProfile
// (identité, documents) et CompanyVocabulary (sigles et lexique maison).
$companyName       = CompanyProfile::name();
$companyDesc       = CompanyProfile::description();
$companyLogo       = CompanyProfile::logoUrl();
$companyLegal      = CompanyProfile::legalMention();
$vocabulary        = CompanyVocabulary::raw();
$templateInfo      = CompanyProfile::templateInfo();
$templateError     = '';
$wordTemplateInfo  = CompanyProfile::wordTemplateInfo();
$wordTemplateError = '';
$fileIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>';
$downloadIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
?>
<div class="admin-content brand-page company-page">
    <div class="brand-page-head">
        <div>
            <h1><?= te('Entreprise') ?></h1>
            <p class="brand-lead">
                <?= str_replace(['{a}', '{/a}'], ['<a href="/admin/settings">', '</a>'],
                    te("L'organisation qui utilise MIA. Son nom, son descriptif et son vocabulaire sont donnés à l'assistant dans chaque conversation ; son logo, sa mention de pied de page et ses modèles PowerPoint et Word habillent les documents produits. Le nom, les logos et les couleurs de l'application se règlent dans {a}Marque & apparence{/a}.")) ?>
            </p>
        </div>
        <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
    </div>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/company" enctype="multipart/form-data" data-autosave class="brand-form" id="entreprise">
        <?= Csrf::field() ?>
        <div class="brand-grid">

            <!-- Présentation : ce que l'assistant sait de l'entreprise -->
            <section class="card brand-card" aria-labelledby="companyPresentationTitle" id="presentation">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="companyPresentationTitle"><?= te('Présentation', [], 'entreprise') ?></h3>
                        <p><?= te('Ajoutée au début de chaque conversation, pour tous les assistants et tous les modèles.') ?></p>
                    </div>
                </div>
                <div class="brand-fields company-fields">
                    <div class="form-group">
                        <label for="companyName"><?= te("Nom de l'entreprise") ?></label>
                        <input type="text" name="<?= CompanyProfile::SETTING_NAME ?>" id="companyName" class="form-input"
                               maxlength="<?= CompanyProfile::NAME_MAX ?>" data-preview
                               value="<?= htmlspecialchars($companyName) ?>" placeholder="<?= te('Ex : {exemple}', ['exemple' => 'Acme Industries']) ?>">
                        <p class="brand-hint"><?= te("Repère de l'assistant (« notre entreprise ») et pied des documents, à défaut de mention.") ?></p>
                    </div>
                    <div class="form-group company-desc-group">
                        <label for="companyDescription"><?= te("Descriptif de l'entreprise") ?></label>
                        <!-- Rubriques : un clic ajoute (ou retrouve) la ligne « Rubrique : »
                             dans le descriptif ; une rubrique remplie est cochée. -->
                        <div class="company-topics" role="group" aria-label="<?= te('Rubriques du descriptif') ?>">
                            <?php /* Rubriques TRADUITES : le libellé posé dans le descriptif est
                                     celui que la personne lit (« Activity: » en anglais). */ ?>
                            <?php foreach (CompanyProfile::descriptionTopics() as $key => $topic): ?>
                                <button type="button" class="company-topic" data-topic="<?= htmlspecialchars($topic['label']) ?>"
                                        title="<?= htmlspecialchars(ucfirst($topic['hint'])) ?>">
                                    <span class="company-topic-mark" aria-hidden="true"></span><?= htmlspecialchars($topic['label']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <textarea name="<?= CompanyProfile::SETTING_DESCRIPTION ?>" id="companyDescription" class="form-input"
                                  rows="8" maxlength="<?= CompanyProfile::DESCRIPTION_MAX ?>" data-preview
                                  placeholder="<?= htmlspecialchars(CompanyProfile::descriptionExamples()[0]['text']) ?>"><?= htmlspecialchars($companyDesc) ?></textarea>
                        <p class="brand-hint company-desc-foot">
                            <span><?= str_replace('{n}', '<span id="companyDescriptionCount">' . mb_strlen($companyDesc) . '</span>', te('{n} / {max} caractères', ['max' => CompanyProfile::DESCRIPTION_MAX])) ?></span>
                            <span class="company-examples"><?= te("Partir d'un exemple :") ?>
                                <?php foreach (CompanyProfile::descriptionExamples() as $i => $example): ?>
                                    <button type="button" class="company-example" data-example="<?= $i ?>"><?= htmlspecialchars($example['label']) ?></button>
                                <?php endforeach; ?>
                            </span>
                        </p>
                    </div>
                </div>
                <p class="brand-note">
                    <?= teb("Une {b}information{/b}, pas une consigne : MIA s'en sert pour comprendre « notre offre » ou « nos clients », et ne cite l'entreprise que si c'est utile. Une consigne de comportement (ton, tutoiement) va dans le prompt d'un assistant.") ?>
                </p>
            </section>

            <!-- Habillage des documents : logo et mention de pied de page -->
            <section class="card brand-card" aria-labelledby="companyDressTitle" id="logo">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="companyDressTitle"><?= te('Habillage des documents') ?></h3>
                        <p><?= te("Présentations, documents Word et PDF produits par MIA — l'interface garde le logo de l'application.") ?></p>
                    </div>
                </div>
                <div class="brand-logo-tile company-logo-tile">
                    <div class="brand-logo-frame">
                        <img id="companyLogoPreview" src="<?= htmlspecialchars($companyLogo) ?>" alt="<?= te("Logo de l'entreprise") ?>"<?= $companyLogo === '' ? ' hidden' : '' ?>>
                        <span id="companyLogoEmpty" class="brand-logo-empty" aria-hidden="true"<?= $companyLogo !== '' ? ' hidden' : '' ?>>+</span>
                    </div>
                    <div class="brand-logo-body">
                        <strong class="brand-logo-title"><?= te("Logo de l'entreprise") ?></strong>
                        <input type="file" name="company_logo" id="companyLogoInput" accept="image/png,image/jpeg"
                               hidden onchange="previewCompanyLogo(this)">
                        <div class="brand-logo-actions">
                            <button type="button" class="btn-secondary" id="companyLogoPick" onclick="document.getElementById('companyLogoInput').click()">
                                <?= $companyLogo !== '' ? te('Changer le logo') : te('Ajouter le logo') ?>
                            </button>
                            <!-- Une affectation par script ne déclenche aucun évènement :
                                 sans le `change` explicite, la suppression ne partirait jamais. -->
                            <button type="button" class="btn-secondary brand-btn-danger" id="companyLogoRemove"<?= $companyLogo === '' ? ' hidden' : '' ?>
                                    onclick="const h=document.getElementById('removeCompanyLogo');h.value='1';h.dispatchEvent(new Event('change',{bubbles:true}));const i=document.getElementById('companyLogoPreview');i.hidden=true;i.removeAttribute('src');document.getElementById('companyLogoEmpty').hidden=false;this.hidden=true">
                                <?= te('Supprimer') ?>
                            </button>
                        </div>
                        <!-- Drapeau à usage unique : remis à vide après l'enregistrement. -->
                        <input type="hidden" name="remove_company_logo" id="removeCompanyLogo" value="" data-autosave-reset>
                        <p class="brand-hint"><?= te("PNG ou JPG, idéalement sur fond transparent — les autres formats ne s'affichent ni dans PowerPoint, ni dans Word, ni dans un PDF. En-tête des documents, couverture et pied des présentations ; un modèle déposé ci-dessous apporte le sien.") ?></p>
                    </div>
                </div>
                <div class="form-group company-legal-group">
                    <label for="companyLegal"><?= te('Mention de pied de page') ?></label>
                    <input type="text" name="<?= CompanyProfile::SETTING_LEGAL ?>" id="companyLegal" class="form-input"
                           maxlength="<?= CompanyProfile::LEGAL_MAX ?>" value="<?= htmlspecialchars($companyLegal) ?>"
                           placeholder="<?= te('Ex : Acme SA · 12 rue des Forges, 44000 Nantes · SIRET 123 456 789 00012 · Document interne') ?>">
                    <p class="brand-hint"><?= te("Une ligne, au pied de chaque page, avec le numéro de page : documents Word et PDF, présentations, exports d'une réponse. Vide : le nom de l'entreprise. Un modèle qui a son propre pied de page le garde.") ?></p>
                </div>
            </section>

            <!-- Vocabulaire de l'entreprise (venu de Modèles IA → Modèles) -->
            <section class="card brand-card" aria-labelledby="companyVocabularyTitle" id="vocabulaire">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8M8 11h6"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="companyVocabularyTitle"><?= te("Vocabulaire de l'entreprise") ?></h3>
                        <p><?= te("Sigles et lexique maison, pour que l'assistant comprenne « un CRA » ou « le TJM » sans explication.") ?></p>
                    </div>
                </div>
                <div class="form-group company-vocab-group">
                    <label for="companyVocabulary"><?= te('Un terme par ligne, « terme : définition »') ?></label>
                    <textarea name="<?= CompanyVocabulary::SETTING ?>" id="companyVocabulary" class="form-input company-vocab"
                              rows="11" maxlength="<?= CompanyVocabulary::MAX_CHARS ?>" data-preview spellcheck="false"
                              placeholder="<?= htmlspecialchars(implode("\n", [
                                  t('Exemples :'),
                                  t('- MAD : Mise À Disposition (contrat de travail)'),
                                  t('- CRA : Compte Rendu d\'Activité'),
                                  t('- TJM : Taux Journalier Moyen'),
                                  t('- ESN : Entreprise de Services du Numérique'),
                                  t('- SLA : Service Level Agreement (accord de niveau de service)'),
                              ])) ?>"><?= htmlspecialchars($vocabulary) ?></textarea>
                    <p class="brand-hint">
                        <?= str_replace('{n}', '<span id="companyVocabularyCount">' . mb_strlen($vocabulary) . '</span>',
                            te("{n} caractères — jusqu'à {max}, le glossaire part en entier à chaque message ; au-delà, seules les entrées dont le terme (ou le début de la définition) apparaît dans la conversation sont envoyées, recalculées à chaque message.", ['max' => Format::credits(CompanyVocabulary::FULL_CHARS)])) ?>
                    </p>
                </div>
            </section>

            <!-- Ce que l'assistant lit : les fragments exacts, recalculés à la frappe -->
            <section class="card brand-card company-preview-card" aria-labelledby="companyPreviewTitle" id="apercu">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="companyPreviewTitle"><?= te("Ce que l'assistant lit") ?></h3>
                        <p><?= te('Le texte exact ajouté au début des conversations, tenu à jour pendant la saisie.') ?></p>
                    </div>
                </div>
                <div class="form-group company-question-group">
                    <label for="companyPreviewQuestion"><?= te('Essayer avec une question') ?></label>
                    <!-- Hors du formulaire enregistré : ni `name`, ni enregistrement. -->
                    <input type="text" id="companyPreviewQuestion" class="form-input" data-autosave-skip autocomplete="off"
                           placeholder="<?= te('Ex : quel TJM proposer pour une mission en MAD ?') ?>">
                    <p class="brand-hint"><?= te('Montre les entrées du vocabulaire qui seraient retenues pour ce message.') ?></p>
                </div>
                <div class="company-preview-stats" id="companyPreviewStats" aria-live="polite"></div>
                <pre class="company-preview-text" id="companyPreviewText" tabindex="0"><?= te("Calcul de l'aperçu…") ?></pre>
            </section>

            <!-- Modèle PowerPoint officiel -->
            <section class="card brand-card brand-card--wide" aria-labelledby="companyTemplateTitle" id="modele-powerpoint">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/><path d="M7 8h6M7 12h4"/></svg>
                    </span>
                    <div style="flex:1;min-width:0">
                        <h3 id="companyTemplateTitle"><?= te('Modèle PowerPoint') ?></h3>
                        <p><?= te("Le modèle officiel de l'entreprise (.pptx ou .potx, 20 Mo au plus) : les présentations téléchargées depuis la conversation reprennent ses mises en page, ses polices, ses couleurs et son pied de page.") ?></p>
                    </div>
                    <div class="brand-logo-actions company-template-actions">
                        <input type="file" name="company_template" id="companyTemplateInput" hidden data-template-input="pptx"
                               accept=".pptx,.potx,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.openxmlformats-officedocument.presentationml.template">
                        <button type="button" class="btn-secondary" id="companyTemplatePick"
                                onclick="document.getElementById('companyTemplateInput').click()">
                            <?= $templateInfo !== null ? te('Remplacer le modèle') : te('Déposer un modèle') ?>
                        </button>
                        <button type="button" class="btn-secondary brand-btn-danger" id="companyTemplateRemove"<?= $templateInfo === null ? ' hidden' : '' ?>
                                onclick='if(!confirm(<?= tj('Retirer le modèle PowerPoint ? Les présentations reprendront la mise en page de MIA.') ?>))return;const h=document.getElementById("removeCompanyTemplate");h.value="1";h.dispatchEvent(new Event("change",{bubbles:true}));'>
                            <?= te('Retirer') ?>
                        </button>
                    </div>
                </div>
                <input type="hidden" name="remove_company_template" id="removeCompanyTemplate" value="" data-autosave-reset>
                <div class="company-template">
                    <?php require ROOT . '/templates/partials/company-template-status.php'; ?>
                </div>
                <div class="company-sample">
                    <a class="btn-secondary company-sample-btn" href="/admin/company/sample/pptx"><?= $downloadIcon ?> <?= te("Télécharger une présentation d'essai") ?></a>
                    <p class="brand-hint"><?= str_replace(['{a}', '{/a}'], ['<a href="/admin/backup">', '</a>'],
                        te("Fabriquée comme dans la conversation, elle passe par chaque mise en page : couverture, contenu, intercalaire, deux colonnes, comparaison, chiffres, tableau, étapes, citation. Les slides d'exemple du modèle ne sont pas reprises ; le PDF « une page par slide » garde la mise en page de MIA, aux couleurs du modèle. Le fichier n'est pas accessible depuis le web et fait partie de la {a}sauvegarde de la configuration{/a}.")) ?></p>
                </div>
            </section>

            <!-- Modèle Word officiel -->
            <section class="card brand-card brand-card--wide" aria-labelledby="companyWordTemplateTitle" id="modele-word">
                <div class="card-head">
                    <span class="card-ic" aria-hidden="true"><?= $fileIcon ?></span>
                    <div style="flex:1;min-width:0">
                        <h3 id="companyWordTemplateTitle"><?= te('Modèle Word') ?></h3>
                        <p><?= te("Le modèle officiel de l'entreprise (.docx ou .dotx, 20 Mo au plus) : les documents Word produits par MIA — fichiers livrés dans la conversation, exports d'une réponse — prennent ses styles de titres et de texte, ses polices, sa mise en page, ses en-têtes et ses pieds de page.") ?></p>
                    </div>
                    <div class="brand-logo-actions company-template-actions">
                        <input type="file" name="company_word_template" id="companyWordTemplateInput" hidden data-template-input="docx"
                               accept=".docx,.dotx,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.wordprocessingml.template">
                        <button type="button" class="btn-secondary" id="companyWordTemplatePick"
                                onclick="document.getElementById('companyWordTemplateInput').click()">
                            <?= $wordTemplateInfo !== null ? te('Remplacer le modèle') : te('Déposer un modèle') ?>
                        </button>
                        <button type="button" class="btn-secondary brand-btn-danger" id="companyWordTemplateRemove"<?= $wordTemplateInfo === null ? ' hidden' : '' ?>
                                onclick='if(!confirm(<?= tj('Retirer le modèle Word ? Les documents reprendront la mise en page de MIA.') ?>))return;const h=document.getElementById("removeCompanyWordTemplate");h.value="1";h.dispatchEvent(new Event("change",{bubbles:true}));'>
                            <?= te('Retirer') ?>
                        </button>
                    </div>
                </div>
                <input type="hidden" name="remove_company_word_template" id="removeCompanyWordTemplate" value="" data-autosave-reset>
                <div class="company-template">
                    <?php require ROOT . '/templates/partials/company-word-template-status.php'; ?>
                </div>
                <div class="company-sample">
                    <a class="btn-secondary company-sample-btn" href="/admin/company/sample/docx"><?= $downloadIcon ?> <?= te("Télécharger un document d'essai") ?></a>
                    <p class="brand-hint"><?= te("Titres de trois niveaux, listes, tableau, lien et citation, fabriqués comme dans la conversation. Le contenu d'exemple du modèle n'est pas repris. Le fichier n'est pas accessible depuis le web et fait partie de la sauvegarde de la configuration.") ?></p>
                </div>
            </section>
        </div>
    </form>
</div>

<script>
// Textes de l'écran, traduits côté serveur (ce script n'en écrit aucun).
const COMPANY_TXT = {
    topicLine:      <?= tj('{rubrique} : ') ?>,
    replaceConfirm: <?= tj('Remplacer le descriptif actuel par cet exemple ?') ?>,
    nothingAdded:   <?= tj("Rien n'est ajouté : renseignez le nom, le descriptif ou le vocabulaire.") ?>,
    tokens:         <?= tj('≈ {n} jetons ajoutés à ce message') ?>,
    entryOne:       <?= tj('{n} entrée reconnue') ?>,
    entryMany:      <?= tj('{n} entrées reconnues') ?>,
    freeOne:        <?= tj('{n} ligne libre') ?>,
    freeMany:       <?= tj('{n} lignes libres') ?>,
    allShort:       <?= tj('Vocabulaire : {entrees} — court, envoyé en entier.') ?>,
    allShortFree:   <?= tj('Vocabulaire : {entrees}, {lignes} — court, envoyé en entier.') ?>,
    keptOne:        <?= tj('{n} retenue ({termes})') ?>,
    keptMany:       <?= tj('{n} retenues ({termes})') ?>,
    keptNone:       <?= tj('aucune entrée citée') ?>,
    selected:       <?= tj('Vocabulaire : {entrees} entrées, {chars} caractères (≈ {tokens} jetons en entier) — envoi sélectif : {retenues}.') ?>,
    selectedTry:    <?= tj('Vocabulaire : {entrees} entrées, {chars} caractères (≈ {tokens} jetons en entier) — envoi sélectif : {retenues} ; essayez une question.') ?>,
    unavailable:    <?= tj('Aperçu indisponible pour le moment.') ?>,
    analysing:      <?= tj('Analyse du modèle…') ?>,
    drop:           <?= tj('Déposer un modèle') ?>,
    replace:        <?= tj('Remplacer le modèle') ?>,
    changeLogo:     <?= tj('Changer le logo') ?>,
    addLogo:        <?= tj('Ajouter le logo') ?>,
};
// Langue de la page (window.I18N, posé par head-meta) : nombres et pluriels.
const COMPANY_EN = !!(window.I18N && window.I18N.lang === 'en');
const companyFmt = (s, vars) => s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
const companyPlural = (n, un, plusieurs) => ((COMPANY_EN ? n !== 1 : n > 1) ? plusieurs : un);

function previewCompanyLogo(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('companyLogoPreview');
        img.src = e.target.result;
        img.hidden = false;
        const empty = document.getElementById('companyLogoEmpty');
        if (empty) empty.hidden = true;
    };
    reader.readAsDataURL(file);
}

(function () {
    const byId = id => document.getElementById(id);
    const desc = byId('companyDescription');
    const vocab = byId('companyVocabulary');
    const examples = <?= json_encode(array_column(CompanyProfile::descriptionExamples(), 'text'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Une affectation par script n'émet rien : l'évènement est rejoué pour que
    // l'enregistrement, le compteur et l'aperçu suivent comme pour une frappe.
    const poser = (field, value) => {
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    };

    // ---- Compteurs ----
    const compter = (field, out) => {
        if (!field || !out) return;
        const maj = () => { out.textContent = String(field.value.length); };
        field.addEventListener('input', maj);
    };
    compter(desc, byId('companyDescriptionCount'));
    compter(vocab, byId('companyVocabularyCount'));

    // ---- Rubriques du descriptif ----
    const echapper = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const ligneRubrique = label => new RegExp('^[ \\t]*' + echapper(label) + '[ \\t]*:(.*)$', 'mi');
    const peindreRubriques = () => {
        document.querySelectorAll('.company-topic').forEach(btn => {
            const m = desc.value.match(ligneRubrique(btn.dataset.topic));
            const rempli = !!(m && m[1].trim() !== '');
            btn.classList.toggle('is-done', rempli);
            btn.setAttribute('aria-pressed', rempli ? 'true' : 'false');
        });
    };
    if (desc) {
        desc.addEventListener('input', peindreRubriques);
        peindreRubriques();
        document.querySelectorAll('.company-topic').forEach(btn => {
            btn.addEventListener('click', () => {
                const label = btn.dataset.topic;
                const re = ligneRubrique(label);
                const m = re.exec(desc.value);
                let fin;
                if (m) {
                    // Rubrique déjà là : le curseur va au bout de sa ligne.
                    fin = m.index + m[0].length;
                } else {
                    const avant = desc.value.replace(/\s+$/, '');
                    const ajout = (avant === '' ? '' : avant + '\n') + companyFmt(COMPANY_TXT.topicLine, { rubrique: label });
                    if (ajout.length > desc.maxLength) { return; }
                    poser(desc, ajout);
                    fin = ajout.length;
                }
                desc.focus();
                desc.setSelectionRange(fin, fin);
            });
        });

        // ---- Exemples à poser d'un clic ----
        // Un texte posé par un exemple se remplace sans rien demander ; un
        // texte ÉCRIT ne se remplace qu'après confirmation.
        let dernierExemple = null;
        document.querySelectorAll('.company-example').forEach(btn => {
            btn.addEventListener('click', () => {
                const texte = examples[Number(btn.dataset.example)] || '';
                const actuel = desc.value.trim();
                if (actuel !== '' && actuel !== dernierExemple
                    && !confirm(COMPANY_TXT.replaceConfirm)) {
                    return;
                }
                poser(desc, texte);
                dernierExemple = texte.trim();
                desc.focus();
            });
        });
    }

    // ---- Ce que l'assistant lit ----
    const question = byId('companyPreviewQuestion');
    const out = byId('companyPreviewText');
    const stats = byId('companyPreviewStats');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let minuteur = null;
    let sequence = 0;
    const nombre = n => Number(n).toLocaleString(COMPANY_EN ? 'en-US' : 'fr-FR');

    const rendre = data => {
        const parties = [data.company, data.vocabulary].filter(p => p && p.trim() !== '');
        out.textContent = parties.length ? parties.join('\n\n') : COMPANY_TXT.nothingAdded;
        const total = (data.tokens.company || 0) + (data.tokens.vocabulary || 0);
        const v = data.vocab;
        const lignes = [];
        lignes.push(companyFmt(COMPANY_TXT.tokens, { n: nombre(total) }));
        if (v.mode === 'all') {
            const entrees = companyFmt(companyPlural(v.entries, COMPANY_TXT.entryOne, COMPANY_TXT.entryMany), { n: nombre(v.entries) });
            lignes.push(v.free
                ? companyFmt(COMPANY_TXT.allShortFree, {
                    entrees,
                    lignes: companyFmt(companyPlural(v.free, COMPANY_TXT.freeOne, COMPANY_TXT.freeMany), { n: nombre(v.free) }),
                })
                : companyFmt(COMPANY_TXT.allShort, { entrees }));
        } else if (v.mode === 'selected') {
            const retenues = v.matched
                ? companyFmt(companyPlural(v.matched, COMPANY_TXT.keptOne, COMPANY_TXT.keptMany), { n: nombre(v.matched), termes: v.terms.join(', ') })
                : COMPANY_TXT.keptNone;
            const modele = question && question.value.trim() === '' ? COMPANY_TXT.selectedTry : COMPANY_TXT.selected;
            lignes.push(companyFmt(modele, {
                entrees: nombre(v.entries), chars: nombre(v.chars), tokens: nombre(data.tokens.full), retenues,
            }));
        }
        stats.replaceChildren(...lignes.map(texte => { const p = document.createElement('p'); p.textContent = texte; return p; }));
    };

    const calculer = () => {
        const seq = ++sequence;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('<?= CompanyProfile::SETTING_NAME ?>', byId('companyName').value);
        fd.append('<?= CompanyProfile::SETTING_DESCRIPTION ?>', desc.value);
        fd.append('<?= CompanyVocabulary::SETTING ?>', vocab.value);
        fd.append('question', question ? question.value : '');
        fetch('/admin/company/preview', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => { if (seq === sequence && data && data.ok) { rendre(data); } })
            .catch(() => { if (seq === sequence) { stats.textContent = COMPANY_TXT.unavailable; } });
    };
    const planifier = () => { clearTimeout(minuteur); minuteur = setTimeout(calculer, 350); };
    document.querySelectorAll('[data-preview]').forEach(f => f.addEventListener('input', planifier));
    if (question) { question.addEventListener('input', planifier); }
    if (out) { calculer(); }

    // ---- Dépôt d'un modèle : le bouton dit que l'analyse est en cours ----
    const PICKS = { pptx: 'companyTemplatePick', docx: 'companyWordTemplatePick' };
    document.querySelectorAll('[data-template-input]').forEach(input => {
        const pick = byId(PICKS[input.dataset.templateInput]);
        if (!pick) return;
        input.addEventListener('change', () => {
            if (input.files.length) { pick.dataset.label = pick.textContent; pick.textContent = COMPANY_TXT.analysing; pick.disabled = true; }
        });
    });
    // Envoi en échec (réseau, fichier trop lourd pour le serveur) : les
    // boutons ne doivent pas rester bloqués sur « Analyse ».
    document.addEventListener('mia:autosave-failed', () => {
        Object.values(PICKS).forEach(id => {
            const pick = byId(id);
            if (pick && pick.disabled) { pick.disabled = false; pick.textContent = pick.dataset.label || COMPANY_TXT.drop; }
        });
    });
})();

// ---- Après un enregistrement automatique ----
// La page ne se rechargeant pas, logo et état des modèles seraient restés sur
// leur rendu d'origine : c'est la réponse du serveur qui fait foi.
document.addEventListener('mia:autosaved', function (e) {
    const data = e.detail?.data || {};

    // Blocs des modèles rendus par le serveur (analyse ou refus).
    [
        ['company_template', 'companyTemplateStatus', 'companyTemplatePick', 'companyTemplateRemove'],
        ['company_word_template', 'companyWordTemplateStatus', 'companyWordTemplatePick', 'companyWordTemplateRemove'],
    ].forEach(([cle, bloc, choix, retrait]) => {
        if (typeof data[cle + '_html'] !== 'string') return;
        const block = document.getElementById(bloc);
        if (block) { block.outerHTML = data[cle + '_html']; }
        const pick = document.getElementById(choix);
        const remove = document.getElementById(retrait);
        if (pick) { pick.disabled = false; pick.textContent = data[cle + '_set'] ? COMPANY_TXT.replace : COMPANY_TXT.drop; }
        if (remove) { remove.hidden = !data[cle + '_set']; }
    });

    if (data.company_logo_url !== undefined) {
        const img = document.getElementById('companyLogoPreview');
        const empty = document.getElementById('companyLogoEmpty');
        const remove = document.getElementById('companyLogoRemove');
        const pick = document.getElementById('companyLogoPick');
        if (img && data.company_logo_url) {
            img.src = data.company_logo_url; img.hidden = false;
            if (empty) { empty.hidden = true; }
            if (remove) { remove.hidden = false; }
            if (pick) { pick.textContent = COMPANY_TXT.changeLogo; }
        } else if (img) {
            img.removeAttribute('src'); img.hidden = true;
            if (empty) { empty.hidden = false; }
            if (remove) { remove.hidden = true; }
            if (pick) { pick.textContent = COMPANY_TXT.addLogo; }
        }
    }
});
</script>
</body>
</html>
