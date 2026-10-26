<?php
$currentUser  = Session::currentUser();
$appName      = Settings::get('app_name', 'MIA');
$feedbackUrl  = (string) Settings::get('feedback_url', '');
$contactEmail = (string) Settings::get('contact_email', '');
$vendorUrl    = (string) Settings::get('vendor_url', '');
$vendorName   = (string) Settings::get('vendor_name', '');
$docsUrl      = trim((string) Settings::get('docs_url', AboutController::DOCS_URL));
$docsUrl      = $docsUrl !== '' ? $docsUrl : AboutController::DOCS_URL;
// Une carte « Pour aller plus loin » par destination : la documentation toujours,
// le formulaire d'idées seulement si l'administrateur en a déclaré un.
$helpCards    = $feedbackUrl !== '' ? 2 : 1;

// Neuf capacités, dans l'ordre où on les découvre : converser, puis nourrir
// l'IA de ses contenus, puis la laisser agir et produire. Chaque carte porte
// sa teinte (--tile) comme les cartes d'outils de l'administration.
$features = [
    [
        'tile'  => '#6366f1',
        'icon'  => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'title' => t('Plusieurs IA, une interface'),
        'text'  => t('OpenAI, Anthropic, Mistral, Gemini, DeepSeek, Kimi, GLM, Perplexity ou un modèle local : changez de modèle à chaque message, sans changer d\'outil.'),
    ],
    [
        'tile'  => '#0ea5e9',
        'icon'  => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'title' => t('Internet, à jour'),
        'text'  => t('La recherche Internet s\'active en un clic : des réponses d\'aujourd\'hui, avec leurs sources, pour l\'actualité, les prix ou une page web que vous collez.'),
    ],
    [
        'tile'  => '#8b5cf6',
        'icon'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'title' => t('Assistants sur mesure'),
        'text'  => t('Des IA préconfigurées par vos équipes : un rôle, un ton, un modèle, des documents de référence et des outils. Partagées par groupe, appelées d\'un « @ ».'),
    ],
    [
        'tile'  => '#f59e0b',
        'icon'  => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'title' => t('Vos connaissances'),
        'text'  => t('Des bases de connaissances nourries de vos documents, d\'un dossier Google Drive, OneDrive ou SharePoint, d\'un agenda, ou du cerveau MyBrain en direct. Les réponses s\'appuient sur vos contenus.'),
    ],
    [
        'tile'  => '#10b981',
        'icon'  => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'title' => t('Vos outils du quotidien'),
        'text'  => t('Gmail, Outlook, Google Drive, agendas, tâches, Slack : {app} lit, cherche et prépare. Chaque écriture attend votre approbation avant de partir.', ['app' => $appName]),
    ],
    [
        'tile'  => '#ec4899',
        'icon'  => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'title' => t('Une IA qui agit'),
        'text'  => t('Un plan de travail visible, une recherche dans toutes vos sources en même temps, des actions enchaînées jusqu\'au résultat — et un bouton Continuer quand il reste des étapes.'),
    ],
    [
        'tile'  => '#f97316',
        'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'title' => t('Des créations prêtes à l\'emploi'),
        'text'  => t('Images, présentations, pages web, itinéraires, classeurs Excel, documents Word ou PDF : {app} les fabrique dans la conversation, et vous les retrouvez dans Mes créations.', ['app' => $appName]),
    ],
    [
        'tile'  => '#14b8a6',
        'icon'  => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
        'title' => t('À la voix'),
        'text'  => t('Dictez vos messages, ou conversez à voix haute : {app} écoute, répond et lit sa réponse pendant qu\'elle l\'écrit.', ['app' => $appName]),
    ],
    [
        'tile'  => '#64748b',
        'icon'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'title' => t('Maîtrise & sécurité'),
        'text'  => t('Quotas par personne, budget mensuel, clés chiffrées, conversations cloisonnées par compte, et une approbation humaine avant toute action sur vos données.'),
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= te('À propos -') ?> <?= htmlspecialchars($appName) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page about-page">

<?php $navVariant = 'none'; $navTitle = t('À propos'); require ROOT . '/templates/partials/admin-nav.php'; ?>

    <main class="admin-content">
    <div class="about-wrap">

        <!-- Hero : le texte à gauche, l'illustration à droite (empilés sur mobile) -->
        <section class="about-hero">
            <div class="about-hero-glow" aria-hidden="true"></div>
            <div class="about-hero-text">
                <p class="about-kicker"><?= te('À propos') ?></p>
                <h1 class="about-title">
                    <span class="about-title-app"><?= htmlspecialchars($appName) ?></span>
                    <?= te('réunit les meilleures IA au service de votre travail.') ?>
                </h1>
                <p class="about-lead">
                    <?= te('Une interface unique pour les modèles d\'OpenAI, d\'Anthropic, de Mistral, de Google et d\'autres — avec vos documents, vos mails et vos agendas à portée de main, et des créations prêtes à l\'emploi.') ?>
                </p>
                <div class="about-hero-actions">
                    <a href="<?= htmlspecialchars($docsUrl) ?>" target="_blank" rel="noopener noreferrer"
                       class="btn-primary about-cta"><?= te('Consulter la documentation ↗') ?></a>
                    <?php if ($feedbackUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($feedbackUrl) ?>" target="_blank" rel="noopener"
                       class="btn-secondary about-cta"><?= te('💡 Déclarer une idée ou un bug') ?></a>
                    <?php endif; ?>
                    <button type="button" id="versionBtn" class="about-version-badge"
                            title="<?= te('Voir les dernières améliorations') ?>">
                        <span class="dot"></span> v<?= htmlspecialchars(APP_VERSION) ?>
                        <span class="about-version-badge-cta"><?= te('Nouveautés') ?></span>
                    </button>
                </div>
                <p class="about-hero-by">
                    <?= te('Une application créée par') ?> <a href="#editeur"><?= te('MyAlfred.ai') ?></a>
                </p>
            </div>
            <figure class="about-hero-art">
                <img src="/assets/img/about-mia.webp" width="1254" height="1254" fetchpriority="high"
                     alt="<?= te('Un tentacule de pieuvre entouré des logos des IA réunies dans {app}', ['app' => $appName]) ?>">
            </figure>
        </section>

        <!-- Ce que l'app fait -->
        <section class="about-section" aria-labelledby="aboutFeaturesTitle">
            <h2 class="about-section-title" id="aboutFeaturesTitle"><?= te('Ce que {app} fait pour vous', ['app' => $appName]) ?></h2>
            <p class="about-section-lead"><?= te('Tout se passe dans une conversation : vous choisissez l\'IA, vous lui donnez accès à ce qu\'il faut, elle répond, cherche, prépare et produit.') ?></p>
            <div class="about-features">
                <?php foreach ($features as $f): ?>
                <article class="about-feature" style="--tile:<?= htmlspecialchars($f['tile']) ?>">
                    <span class="about-feature-icon" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $f['icon'] ?></svg>
                    </span>
                    <h3><?= htmlspecialchars($f['title']) ?></h3>
                    <p><?= htmlspecialchars($f['text']) ?></p>
                </article>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Pour aller plus loin : la documentation, et le formulaire d'idées -->
        <section class="about-section" aria-labelledby="aboutHelpTitle">
            <h2 class="about-section-title" id="aboutHelpTitle"><?= te('Pour aller plus loin') ?></h2>
            <div class="about-help about-help--<?= $helpCards ?>">
                <article class="about-help-card about-help-card--docs">
                    <span class="about-help-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="16" y2="7"/><line x1="9" y1="11" x2="14" y2="11"/></svg>
                    </span>
                    <div class="about-help-body">
                        <p class="about-help-kicker"><?= te('Documentation') ?></p>
                        <h3><?= te('Tout {app}, expliqué pas à pas', ['app' => $appName]) ?></h3>
                        <p><?= te('Conversations, modèles, assistants, bases de connaissances, connecteurs, outils, crédits, administration : chaque fonctionnalité a sa page, avec les gestes à faire et les réglages qui comptent.') ?></p>
                        <p class="about-help-note"><?= te('Une question rapide ? L\'assistant « MIA : mode d\'emploi » y répond directement dans le chat.') ?></p>
                    </div>
                    <a href="<?= htmlspecialchars($docsUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-primary about-cta">
                        <?= te('Ouvrir la documentation ↗') ?>
                    </a>
                </article>
                <?php if ($feedbackUrl !== ''): ?>
                <article class="about-help-card about-help-card--feedback">
                    <span class="about-help-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/></svg>
                    </span>
                    <div class="about-help-body">
                        <p class="about-help-kicker"><?= te('Vos retours') ?></p>
                        <h3><?= te('Une idée ? Un bug ?') ?></h3>
                        <p><?= te('Votre retour façonne {app}. Partagez une idée ou signalez un problème.', ['app' => $appName]) ?></p>
                    </div>
                    <a href="<?= htmlspecialchars($feedbackUrl) ?>" target="_blank" rel="noopener" class="btn-secondary about-cta">
                        <?= te('Partager un retour ↗') ?>
                    </a>
                </article>
                <?php endif; ?>
            </div>
        </section>

        <!-- L'éditeur : origine, contact, société -->
        <section class="about-section about-editor" id="editeur" aria-labelledby="aboutEditorTitle">
            <h2 class="about-section-title" id="aboutEditorTitle"><?= te('Derrière {app} : MyAlfred.ai', ['app' => $appName]) ?></h2>
            <p class="about-section-lead"><?= te('La mission de l\'équipe : conseiller, former et accompagner les entreprises dans leur adoption de l\'IA de manière pragmatique.') ?></p>
            <div class="about-grid about-grid--<?= ($contactEmail !== '' || $vendorUrl !== '') ? 3 : 2 ?>">
                <article class="about-block">
                    <h3><?= te('Origine du projet') ?></h3>
                    <p><?= te('MyAlfred.ai a été imaginée durant l\'été 2024 par Axel VILLECHALANE et Grégory LE COENT, puis créée officiellement le 9 décembre 2024.') ?></p>
                    <p><?= te('{app} est née de ce travail de terrain : une application pensée pour rendre l\'IA concrète, utile et accessible au quotidien des entreprises.', ['app' => $appName]) ?></p>
                </article>

                <?php if ($contactEmail !== '' || $vendorUrl !== ''): ?>
                <article class="about-block">
                    <h3><?= te('Contact & site web') ?></h3>
                    <?php if ($contactEmail !== ''): ?>
                    <p><?= te('Pour toute demande d\'information :') ?></p>
                    <p><a class="about-link" href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a></p>
                    <?php endif; ?>
                    <?php if ($vendorUrl !== ''): ?>
                    <p><?= te('Découvrez l\'univers {editeur} :', ['editeur' => $vendorName !== '' ? $vendorName : t('de l\'éditeur')]) ?></p>
                    <p><a class="about-link" href="<?= htmlspecialchars($vendorUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(preg_replace('#^https?://#', '', $vendorUrl) ?? $vendorUrl) ?> ↗</a></p>
                    <?php endif; ?>
                </article>
                <?php endif; ?>

                <article class="about-block">
                    <h3><?= te('Informations société') ?></h3>
                    <ul class="about-list">
                        <li><strong><?= te('Raison sociale :') ?></strong> <?= te('MyAlfred.ai') ?></li>
                        <li><strong><?= te('Forme juridique :') ?></strong> <?= te('SAS') ?></li>
                        <li><strong><?= te('Siège social :') ?></strong> <?= te('1 rue du Guesclin, 44000 Nantes') ?></li>
                        <li><strong><?= te('Locaux :') ?></strong> <?= te('1 rue des Montgolfières, 44120 Vertou') ?></li>
                        <li><strong><?= te('SIRET :') ?></strong> 938 442 274 00016</li>
                        <li><strong><?= te('RCS :') ?></strong> <?= te('Nantes 938 442 274') ?></li>
                        <li><strong><?= te('TVA intracommunautaire :') ?></strong> <?= te('FR95 938442274') ?></li>
                        <li><strong><?= te('Code NAF / APE :') ?></strong> <?= te('62.01Z (Programmation informatique)') ?></li>
                    </ul>
                </article>
            </div>
        </section>

        <div class="about-actions">
            <a href="/" class="btn-secondary"><?= te('← Retour à l\'application') ?></a>
        </div>
    </div>
    </main>

    <?php require ROOT . '/templates/partials/whats-new-modal.php'; ?>
</body>
</html>
