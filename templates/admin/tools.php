<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Outils') ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?>
</head>
<body class="admin-page">
<?php $page = 'tools'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<?php
    // Droits du rôle sur les outils (Personnes → Rôles) : ajouter,
    // modifier (tests compris), supprimer. Le serveur refuse de toute façon.
    $toolsCan = [
        'create' => Session::can('tools.create'),
        'edit'   => Session::can('tools.edit'),
        'delete' => Session::can('tools.delete'),
    ];
?>

<?php
// Providers HTML : réutilise le catalogue LLM (les tools HTML passent par un LLM).
$providerLabels = [
    'openai'    => 'OpenAI',
    'anthropic' => 'Anthropic (Claude)',
    'gemini'    => 'Gemini (Google)',
    'mistral'   => 'Mistral',
    'deepseek'  => 'DeepSeek',
    'moonshot'  => 'Kimi (Moonshot)',
    'zai'       => 'GLM (Z.ai)',
    'perplexity'=> 'Perplexity',
    // Pas un LLM : les outils n8n et Gamma n'ont ni fournisseur ni modèle,
    // ces colonnes NOT NULL portent une valeur fixe que la liste doit nommer.
    'n8n'       => 'n8n (instance)',
    'gamma'     => 'Gamma (gamma.app)',
];

// Catalogues image / vidéo : lus depuis config/tools_catalog.php via ToolCatalog.
// Pour ajouter un modèle image/vidéo, éditer uniquement le catalogue.
$imageProviders = ToolCatalog::providers('image');
$videoProviders = ToolCatalog::providers('video');

// Modèles arrêtés par leur fournisseur (catalogue, `retirement`) : jamais
// proposés à la CRÉATION. La fiche d'un outil existant, elle, les garde en
// réserve (option masquée) pour afficher le modèle ACTUEL de l'outil — sinon
// l'enregistrement automatique le remplacerait en silence par le premier venu.
$imageProvidersNew = ToolCatalog::offerableProviders('image');
$videoProvidersNew = ToolCatalog::offerableProviders('video');

// Libellé d'un modèle dans les listes : l'arrêt annoncé, ou effectif, s'y lit.
$modelOptionLabel = static function (string $type, string $provider, string $id, array $info): string {
    // Libellé du catalogue : un nom de modèle, mais certains portent un
    // qualificatif français (« natif image », un mois) — leur français est la clé.
    $label = t((string)($info['label'] ?? $id));
    $arret = ToolCatalog::retirement($type, $provider, $id);
    if ($arret === null) {
        return $label;
    }

    $vars = ['modele' => $label, 'date' => Format::dateFr($arret['date'])];

    return $arret['retired'] ? t('{modele} — arrêté le {date}', $vars) : t('{modele} — arrêt le {date}', $vars);
};

// Options d'une liste de modèles de la FICHE (tous les modèles du catalogue,
// les arrêtés marqués `data-retired` : le JS ne les montre que s'il s'agit du
// modèle actuel de l'outil ouvert).
$editModelOptions = static function (string $type, string $provider) use ($modelOptionLabel): string {
    $html = '';
    foreach (ToolCatalog::models($type, $provider) as $id => $info) {
        $id = (string)$id;
        $html .= '<option value="' . htmlspecialchars($id) . '"'
            . (ToolCatalog::isRetired($type, $provider, $id) ? ' data-retired="1"' : '')
            . '>' . htmlspecialchars($modelOptionLabel($type, $provider, $id, (array)$info)) . '</option>';
    }

    return $html;
};

// Outils image existants : proposés comme source d'illustrations pour les outils « Site illustré ».
$imageTools = array_values(array_filter($tools ?? [], static fn($t) => ($t['type'] ?? '') === 'image'));

// Styles graphiques (directive => libellé) lus depuis la SOURCE UNIQUE
// config/tools_catalog.php (ToolCatalog::imageStyles()), partagée avec le popup
// chat (window.IMAGE_STYLES). Plus aucune liste à synchroniser à la main.
$imageStylePresets = [];
foreach (ToolCatalog::imageStyles() as $s) {
    $imageStylePresets[(string)$s['directive']] = (string)$s['label'];
}
// $renderStyleField (sélecteur de style) est défini plus bas, APRÈS $tip dont il dépend.

// Phrases mises en forme : la phrase reste ENTIÈRE dans le dictionnaire (teb()),
// et {i}…{/i}, {code}…{/code} deviennent <em>, <code> APRÈS l'échappement —
// même principe que {b} : seules NOS balises passent.
$balises = static fn(string $html): string => str_replace(
    ['{i}', '{/i}', '{code}', '{/code}'],
    ['<em>', '</em>', '<code>', '</code>'],
    $html
);

// Mode d'emploi n8n : identique à la création et à l'édition — une closure
// plutôt qu'un copier-coller, deux blocs auraient divergé au premier ajout.
$n8nHelp = static function () use ($balises): string {
    ob_start(); ?>
    <div class="n8n-help">
        <?= teb('{b}Côté n8n, trois choses à faire{/b} (la première une seule fois) :') ?>
        <ol>
            <li><?= $balises(teb('{b}Créer une clé API{/b} — {i}menu utilisateur → Settings → n8n API → Create an API key{/i}. Elle ne sert qu\'à {b}lister et décrire{/b} les workflows, jamais à les exécuter. Si l\'API publique est désactivée sur l\'instance ({code}N8N_PUBLIC_API_DISABLED{/code}), réactivez-la.')) ?></li>
            <li><?= $balises(teb('{b}Ajouter un déclencheur Webhook{/b} au workflow — seul ce nœud est appelable (un déclencheur planifié ou manuel ne l\'est pas). Method {code}POST{/code} (les données arrivent dans {code}{{ $json.body }}{/code}) et Respond {i}When Last Node Finishes{/i}, sinon l\'assistant n\'a aucun résultat à restituer. {b}Les Notes du nœud sont la notice lue par l\'assistant{/b} : écrivez-y ce que fait le workflow et les champs attendus.')) ?></li>
            <li><?= teb('{b}Taguer et activer{/b} le workflow. {b}Le tag est l\'unique liste blanche{/b} : un workflow sans ce tag, ou inactif, est invisible de l\'assistant. Retirer le tag coupe l\'accès immédiatement, sans repasser par ici.') ?></li>
        </ol>
        <?= teb('Le bouton {b}Tester{/b} de la liste vérifie la connexion, compte les workflows exposés et contrôle la cohérence de leur protection — sans en déclencher aucun.') ?>
    </div>
    <?php return (string)ob_get_clean();
};

// Mode d'emploi Gamma : même principe (closure partagée création/édition).
$gammaHelp = static function () use ($balises): string {
    ob_start(); ?>
    <div class="n8n-help">
        <?= teb('{b}Côté gamma.app, deux prérequis :{/b}') ?>
        <ol>
            <li><?= teb('{b}Un plan payant{/b} — l\'accès API demande un plan Pro, Ultra, Teams ou Business. Chaque génération consomme des {b}crédits Gamma{/b} du compte (indépendants des crédits MIA).') ?></li>
            <li><?= $balises(teb('{b}Créer une clé API{/b} — {i}gamma.app → paramètres du compte → API keys{/i}. Format {code}sk-gamma-…{/code}, stockée chiffrée, jamais réaffichée.')) ?></li>
        </ol>
        <?= teb('L\'assistant propose la génération, mais {b}rien ne part sans le clic « Approuver »{/b} de l\'utilisateur (chaque génération est payante). Le bouton {b}Tester{/b} de la liste valide la clé en listant les thèmes du workspace — sans consommer un seul crédit.') ?>
    </div>
    <?php return (string)ob_get_clean();
};

// Utilisabilité : un outil est grisé si ce qu'il lui faut manque (clé dédiée
// OU clé globale active du fournisseur, URL n8n, outil image source d'un site
// illustré). Règle : ChatTool::missingPrerequisites, partagée avec les points à
// régler du menu (AdminAttention) — la carte et la pastille disent la même chose.
$providersWithKey = $providersWithKey ?? [];
$toolById   = [];
foreach (($tools ?? []) as $t) {
    $toolById[(int)$t['id']] = $t;
}

// Table [modelId => label] pour retrouver le libellé d'un modèle dans la liste des outils créés.
$imageModelLabels = [];
foreach ($imageProviders as $pInfo) {
    foreach ($pInfo['models'] ?? [] as $id => $info) {
        $imageModelLabels[$id] = t((string)($info['label'] ?? $id));
    }
}
foreach ($videoProviders as $pInfo) {
    foreach ($pInfo['models'] ?? [] as $id => $info) {
        $imageModelLabels[$id] = t((string)($info['label'] ?? $id));
    }
}
?>

<?php
// Bulle d'aide réutilisable (tooltip au survol/focus, accessible clavier).
$tip = function (string $text, bool $right = false): string {
    return '<span class="help-tip' . ($right ? ' tip-right' : '') . '" tabindex="0" role="button" aria-label="' . te('Aide : {texte}', ['texte' => $text]) . '">?'
         . '<span class="help-bubble">' . htmlspecialchars($text) . '</span></span>';
};

// Sélecteur de style des images (réutilisé create + edit). Défini ici car il dépend de $tip.
// $selected = directive courante (preset reconnu, texte personnalisé, ou vide).
$renderStyleField = function (string $selectId, string $customId, string $selected) use ($imageStylePresets, $tip): string {
    $isPreset = $selected !== '' && isset($imageStylePresets[$selected]);
    $isCustom = $selected !== '' && !$isPreset;
    $h = '<div class="form-group" style="margin:12px 0 0;flex:1 1 100%">';
    $h .= '<label>' . te('Style graphique des images') . ' ' . $tip(t('Style appliqué par défaut aux images générées. L\'utilisateur peut le changer au lancement dans le chat.')) . '</label>';
    $h .= '<select name="config_image_style" id="' . htmlspecialchars($selectId) . '" class="form-select cfg-style-select">';
    $h .= '<option value="">' . te('Aucun (l\'IA décide)') . '</option>';
    foreach ($imageStylePresets as $dir => $lbl) {
        $sel = ($selected === $dir) ? ' selected' : '';
        // Libellé du catalogue (le français est la clé) ; la valeur, la directive, ne se traduit pas.
        $h .= '<option value="' . htmlspecialchars($dir) . '"' . $sel . '>' . htmlspecialchars(t($lbl)) . '</option>';
    }
    $h .= '<option value="__custom__"' . ($isCustom ? ' selected' : '') . '>' . te('Personnalisé…') . '</option>';
    $h .= '</select>';
    $h .= '<input type="text" name="config_image_style_custom" id="' . htmlspecialchars($customId) . '" class="form-input cfg-style-custom" maxlength="200" placeholder="' . te('Décrivez le style voulu (ex. aquarelle pastel, rétro années 80…)') . '" value="' . ($isCustom ? htmlspecialchars($selected) : '') . '" style="margin-top:8px;' . ($isCustom ? '' : 'display:none') . '">';
    $h .= '</div>';
    return $h;
};
?>
<div class="admin-content tools-page">
    <div class="tools-head">
        <div class="tools-head-text">
            <h1><?= te('Outils de génération') ?></h1>
            <p class="tools-intro">
                <?= teb('Chaque carte est un outil du chat : {b}images, vidéos, pages web, présentations, dictée vocale ou automatisations n8n{/b}. L\'interrupteur {b}Actif{/b} met l\'outil en service ; il n\'est proposé qu\'aux membres des {b}groupes qui l\'autorisent{/b} (Personnes → Groupes → Droits d\'accès → Outils), et aux administrateurs.') ?>
                <?php /* La pastille d'exemple est posée APRÈS l'échappement, au milieu de la phrase. */ ?>
                <?= str_replace(
                    '{pastille}',
                    $tip(t('Voilà une bulle d\'aide ! Survolez (ou tabulez sur) ces pastilles pour comprendre chaque réglage.')),
                    te('Survolez une pastille {pastille} pour des explications.')
                ) ?>
            </p>
            <p style="margin-top:8px">
                <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
            </p>
        </div>
        <div class="tools-head-actions">
            <span id="toolsTestGlobal"></span>
            <button type="button"
                    id="testAllToolsBtn"
                    class="btn-secondary"
                    <?= (empty($tools) || !$toolsCan['edit']) ? 'disabled' : '' ?>>
                <?= te('Tester tous les outils') ?>
            </button>
            <?php if ($toolsCan['create']): ?>
            <button type="button" id="addToolOpen" class="btn-primary tool-add-btn js-add-tool-open">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= te('Ajouter un outil') ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success ?? false): ?>
        <div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error ?? false): ?>
        <div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card" id="addToolCard" style="margin-bottom:20px" hidden>
        <div class="card-head">
            <span class="card-ic" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </span>
            <div style="flex:1;min-width:0">
                <h3><?= te('Ajouter un outil') ?></h3>
                <p><?= te('Trois étapes : choisissez quoi générer, réglez le moteur, puis nommez et activez.') ?></p>
            </div>
            <button type="button" class="tool-modal-x" id="addToolClose" aria-label="<?= te('Fermer le formulaire d\'ajout') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form method="POST" action="/admin/tools" id="createForm">
            <?= Csrf::field() ?>

            <!-- ÉTAPE 1 — TYPE -->
            <div class="tool-step" data-step="1">
                <button type="button" class="step-head" aria-expanded="false">
                    <span class="step-title"><?= te('Que voulez-vous générer ?') ?></span>
                    <span class="step-summary" id="cStep1Summary"></span>
                    <span class="step-chevron" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
                </button>
                <div class="step-body">
                <div class="type-picker" id="cTypePicker">
                    <label class="type-card is-selected" data-t="image">
                        <input type="radio" name="type" value="image" checked>
                        <span class="tc-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span>
                        <span class="tc-title"><?= te('Image') ?></span>
                        <span class="tc-desc"><?= te('Une illustration ou photo à partir d\'un texte.') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <?php $videoCreatable = $videoProvidersNew !== []; ?>
                    <label class="type-card<?= $videoCreatable ? '' : ' is-disabled' ?>" data-t="video">
                        <input type="radio" name="type" value="video"<?= $videoCreatable ? '' : ' disabled' ?>>
                        <span class="tc-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="14" height="14" rx="2"/><path d="m22 8-6 4 6 4V8z"/></svg></span>
                        <span class="tc-title"><?= te('Vidéo') ?></span>
                        <?php if ($videoCreatable): ?>
                        <span class="tc-desc"><?= te('Une courte vidéo à partir d\'un texte.') ?></span>
                        <?php else: ?>
                        <span class="tc-desc"><?= te('Indisponible : aucun modèle vidéo en service chez les fournisseurs connus de MIA.') ?></span>
                        <?php endif; ?>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <label class="type-card" data-t="html">
                        <input type="radio" name="type" value="html">
                        <span class="tc-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg></span>
                        <span class="tc-title"><?= te('Page HTML') ?></span>
                        <span class="tc-desc"><?= te('Une page web autonome (mini-site, démo).') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <label class="type-card" data-t="html_illustrated">
                        <input type="radio" name="type" value="html_illustrated">
                        <span class="tc-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="9" r="1.6"/><path d="m21 16-4.5-4.5L9 19"/></svg></span>
                        <span class="tc-title"><?= te('Site illustré') ?></span>
                        <span class="tc-desc"><?= te('Une page web avec ses images générées automatiquement.') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <label class="type-card" data-t="map">
                        <input type="radio" name="type" value="map">
                        <span class="tc-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21"/><line x1="8" y1="3" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="21"/></svg></span>
                        <span class="tc-title"><?= te('Carte / Itinéraire') ?></span>
                        <?php if (ChatTool::isBetaType('map')): ?>
                            <?php /* Constantes de ChatTool : leur texte français est la clé. */ ?>
                            <span class="tool-beta-chip" title="<?= htmlspecialchars(t(ChatTool::BETA_HINT)) ?>"><?= htmlspecialchars(t(ChatTool::BETA_LABEL)) ?></span>
                        <?php endif; ?>
                        <span class="tc-desc"><?= te('Un trajet dessiné sur une carte OpenStreetMap (export PNG, Google Maps).') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <?php // Plus créable : une présentation se demande dans la conversation,
                          // « Modifier les slides » sur sa carte ouvre l'éditeur. ?>
                    <label class="type-card is-disabled" data-t="presentation">
                        <input type="radio" name="type" value="presentation" disabled>
                        <span class="tc-ic" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg></span>
                        <span class="tc-title"><?= te('Présentation') ?></span>
                        <span class="tc-desc"><?= te('Remplacé : une présentation se demande dans la conversation, et « Modifier les slides » sur sa carte ouvre l\'éditeur.') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <label class="type-card" data-t="n8n">
                        <input type="radio" name="type" value="n8n">
                        <span class="tc-ic" aria-hidden="true"><img src="/assets/img/n8n.svg" width="22" height="22" alt=""></span>
                        <span class="tc-title"><?= te('Automatisation n8n') ?></span>
                        <span class="tc-desc"><?= te('Vos workflows n8n, déclenchés par l\'assistant après approbation de l\'utilisateur.') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                    <label class="type-card" data-t="gamma">
                        <input type="radio" name="type" value="gamma">
                        <span class="tc-ic" aria-hidden="true"><img src="/assets/img/gamma.svg" width="22" height="22" alt=""></span>
                        <span class="tc-title"><?= te('Présentation Gamma') ?></span>
                        <span class="tc-desc"><?= te('Des présentations générées par gamma.app, après approbation de l\'utilisateur (crédits Gamma payants).') ?></span>
                        <span class="tc-check" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    </label>
                </div>
                </div>
            </div>

            <!-- ÉTAPE 2 — MOTEUR & OPTIONS -->
            <div class="tool-step" data-step="2">
                <button type="button" class="step-head" aria-expanded="false">
                    <span class="step-title"><?= te('Réglez le moteur') ?></span>
                    <span class="step-summary" id="cStep2Summary"></span>
                    <span class="step-chevron" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
                </button>
                <div class="step-body">
                <p class="step-sub" id="cStep2Sub"><?= te('Le « moteur » = le service d\'IA et le modèle précis (clé API du fournisseur à configurer dans Modèles IA). Seuls les réglages utiles au type choisi s\'affichent. Pour la vidéo, durée / taille / format se choisissent dans le chat juste avant de générer.') ?></p>
                <p class="step-sub" id="cStep2SubN8n" style="display:none"><?= te('Aucun modèle d\'IA ici : une automatisation n8n se règle avec l\'adresse de votre instance, une clé API et le tag qui borne son périmètre.') ?></p>
                <p class="step-sub" id="cStep2SubGamma" style="display:none"><?= te('Aucun modèle d\'IA de MIA ici : la génération est faite par gamma.app avec votre clé API (crédits Gamma du compte, plan Pro+ requis).') ?></p>

                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">

                    <!-- Fournisseur Image -->
                    <div class="form-group" style="margin:0;flex:0 0 180px" id="cProviderImageWrap">
                        <label><?= te('Fournisseur', [], 'llm') ?> <?= $tip(t('La société qui fournit le moteur d\'image (OpenAI, Google…).')) ?></label>
                        <select name="provider" id="cProviderImage" class="form-select">
                            <?php foreach ($imageProvidersNew as $pid => $pInfo): ?>
                                <option value="<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars((string)($pInfo['label'] ?? $pid)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Modèle Image OpenAI -->
                    <div class="form-group" style="margin:0;flex:0 0 160px" id="cModelImgOaiWrap">
                        <label><?= te('Modèle') ?> <?= $tip(t('Le moteur précis. Ex. : GPT Image 2 pour du photo-réaliste avec texte net.')) ?></label>
                        <select name="model" id="cModelImgOai" class="form-select">
                            <?php foreach (ToolCatalog::offerableModels('image', 'openai') as $id => $info): ?>
                                <option value="<?= htmlspecialchars($id) ?>" <?= !empty($info['default']) ? 'selected' : '' ?>><?= htmlspecialchars($modelOptionLabel('image', 'openai', (string)$id, (array)$info)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Modèle Image Gemini -->
                    <div class="form-group" style="margin:0;flex:0 0 220px;display:none" id="cModelImgGemWrap">
                        <label><?= te('Modèle') ?> <?= $tip(t('Moteurs Google : Nano Banana 2 (rapide) ou Nano Banana Pro (haute qualité).')) ?></label>
                        <select name="model" id="cModelImgGem" class="form-select" disabled>
                            <?php foreach (ToolCatalog::offerableModels('image', 'gemini') as $id => $info): ?>
                                <option value="<?= htmlspecialchars($id) ?>" <?= !empty($info['default']) ? 'selected' : '' ?>><?= htmlspecialchars($modelOptionLabel('image', 'gemini', (string)$id, (array)$info)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Fournisseur HTML (LLM) -->
                    <div class="form-group" style="margin:0;flex:0 0 200px;display:none" id="cProviderHtmlWrap">
                        <label><?= te('LLM fournisseur') ?> <?= $tip(t('Le modèle de langage qui écrit le code de la page (OpenAI, Claude, Gemini…).')) ?></label>
                        <select name="provider" id="cProviderHtml" class="form-select" disabled>
                            <?php foreach ($providerLabels as $pv => $pl): ?>
                                <option value="<?= $pv ?>"><?= htmlspecialchars($pl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Modèle HTML -->
                    <div class="form-group" style="margin:0;flex:0 0 240px;display:none" id="cModelHtmlWrap">
                        <label><?= te('Modèle') ?> <?= $tip(t('Le modèle précis. Les plus puissants produisent un meilleur code mais coûtent davantage.')) ?></label>
                        <select name="model" id="cModelHtml" class="form-select" disabled></select>
                    </div>

                    <!-- Fournisseur vidéo -->
                    <div class="form-group" style="margin:0;flex:0 0 180px;display:none" id="cProviderVideoWrap">
                        <label><?= te('Fournisseur', [], 'llm') ?> <?= $tip(t('Le service de génération vidéo.')) ?></label>
                        <select name="provider" id="cProviderVideo" class="form-select" disabled>
                            <?php foreach ($videoProvidersNew as $pid => $pInfo): ?>
                                <option value="<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars((string)($pInfo['label'] ?? $pid)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Modèle vidéo -->
                    <div class="form-group" style="margin:0;flex:0 0 180px;display:none" id="cModelVideoWrap">
                        <label><?= te('Modèle') ?> <?= $tip(t('La version du moteur vidéo.')) ?></label>
                        <select name="model" id="cModelVideo" class="form-select" disabled>
                            <?php foreach ($videoProvidersNew as $vpid => $vpInfo): ?>
                                <?php foreach (ToolCatalog::offerableModels('video', (string)$vpid) as $id => $info): ?>
                                <option value="<?= htmlspecialchars($id) ?>" <?= !empty($info['default']) ? 'selected' : '' ?>><?= htmlspecialchars($modelOptionLabel('video', (string)$vpid, (string)$id, (array)$info)) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <!-- Options image : DALL-E / GPT Image.
                     Ni taille ni qualité ici : elles se décident à la génération
                     (qualité standard par défaut). Une valeur figée à l'ajout se
                     serait appliquée à TOUTES les images de l'outil — c'est
                     précisément ce qu'on ne veut plus. Un administrateur qui
                     tient à en imposer une le fait dans la fiche de l'outil. -->
                <div id="cOptsImgOai" style="display:none;margin-top:14px">
                    <div class="alert alert-success" style="margin:0;padding:10px 12px;font-size:12px">
                        <?= teb('Rien à régler ici : la taille de l\'image se décide au moment de la génération, et la qualité est {b}standard{/b} par défaut. Vous pourrez les figer plus tard dans la fiche de l\'outil.') ?>
                    </div>
                </div>

                <!-- Options image : OpenAI natif (ChatGPT via Responses API) -->
                <div id="cOptsImgOaiNative" style="display:none;margin-top:14px">
                    <div class="alert alert-success" style="margin:0;padding:10px 12px;font-size:12px">
                        <?= te('Ce modèle utilise la génération d\'image native de ChatGPT (Responses API). Taille et qualité sont gérées automatiquement — rien à régler ici.') ?>
                    </div>
                </div>

                <!-- Options image : Imagen -->
                <div id="cOptsImgImagen" style="display:none;margin-top:14px">
                    <div class="form-group" style="margin:0;display:inline-block">
                        <label><?= te('Format') ?> <?= $tip(t('Proportions largeur:hauteur. 1:1 carré, 16:9 paysage, 9:16 portrait.')) ?></label>
                        <select name="config_aspect_ratio" class="form-select">
                            <option value="1:1"><?= te('1:1 (carré)') ?></option>
                            <option value="16:9"><?= te('16:9 (paysage)') ?></option>
                            <option value="9:16"><?= te('9:16 (portrait)') ?></option>
                            <option value="4:3">4:3</option>
                            <option value="3:4">3:4</option>
                        </select>
                    </div>
                </div>

                <!-- Options HTML -->
                <div id="cOptsHtml" style="display:none;margin-top:14px">
                    <div class="form-group" style="margin:0 0 12px">
                        <label><?= te('System prompt') ?> <?= $tip(t('Instructions données au modèle avant chaque génération. Pré-rempli avec le texte livré pour ce type d\'outil : réécrivez-le librement, ou videz le champ pour revenir au défaut.')) ?></label>
                        <textarea name="config_system_prompt" id="cCfgSystemPrompt" class="form-input" rows="9"
                                  style="width:100%;resize:vertical;font-family:monospace;font-size:12px"></textarea>
                        <p class="tool-prompt-hint">
                            <?= teb('Texte par défaut de ce type d\'outil, modifiable. Le {b}format de sortie{/b} (page HTML seule, spécification JSON d\'une carte ou d\'une présentation) est ajouté automatiquement et n\'est pas éditable : il fait fonctionner l\'affichage. Champ vidé = retour au texte par défaut.') ?>
                        </p>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div class="form-group" style="margin:0;flex:0 0 220px">
                            <label><?= te('Température') ?> <span id="cTempVal" style="color:var(--accent)">0.7</span> <?= $tip(t('0 = résultat stable et prévisible. 1 = plus créatif et varié.')) ?></label>
                            <input type="range" name="config_temperature" id="cTemp"
                                   min="0" max="1" step="0.1" value="0.7"
                                   style="width:100%;accent-color:var(--accent)">
                        </div>
                    </div>
                </div>

                <!-- Options Site illustré : outil image source + nombre d'images -->
                <div id="cOptsIllustrated" style="display:none;margin-top:14px">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div class="form-group" style="margin:0;flex:0 0 300px">
                            <label><?= te('Outil image pour les illustrations') ?> <?= $tip(t('Quel outil « Image » sera utilisé pour générer les visuels insérés dans la page. Créez-en un d\'abord si la liste est vide.')) ?></label>
                            <select name="config_image_tool_id" class="form-select">
                                <option value=""><?= te('— Choisir un outil image —') ?></option>
                                <?php foreach ($imageTools as $it): ?>
                                    <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['name']) ?> (<?= htmlspecialchars((string)$it['provider'] . ' · ' . (string)$it['model']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 150px">
                            <label><?= te('Images max / page') ?> <?= $tip(t('Nombre maximum d\'illustrations générées par page. Plus = plus long et plus coûteux.')) ?></label>
                            <input type="number" name="config_max_images" class="form-input" min="1" max="6" value="3">
                        </div>
                    </div>
                    <?= $renderStyleField('cImageStyle', 'cImageStyleCustom', '') ?>
                    <?php if (empty($imageTools)): ?>
                        <div class="alert alert-error" style="margin:10px 0 0;padding:10px 12px;font-size:12px">
                            <?= te('Aucun outil image disponible. Créez d\'abord un outil de type « Image », puis revenez le sélectionner ici.') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Options Présentation : outil image par défaut + nombre de slides -->
                <div id="cOptsPresentation" style="display:none;margin-top:14px">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div class="form-group" style="margin:0;flex:0 0 300px">
                            <label><?= te('Outil image pour les illustrations') ?> <?= $tip(t('Proposé par défaut dans le volet. L\'utilisateur peut refuser les images au moment de la demande.')) ?></label>
                            <select name="config_image_tool_id" class="form-select">
                                <option value=""><?= te('— Aucun (présentations sans image) —') ?></option>
                                <?php foreach ($imageTools as $it): ?>
                                    <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['name']) ?> (<?= htmlspecialchars((string)$it['provider'] . ' · ' . (string)$it['model']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 170px">
                            <label><?= te('Slides par défaut') ?> <?= $tip(t('Nombre pré-sélectionné dans le volet. L\'utilisateur reste libre de le changer.')) ?></label>
                            <input type="number" name="config_default_slides" class="form-input"
                                   min="<?= DeckSpec::MIN_SLIDES ?>" max="<?= DeckSpec::MAX_SLIDES ?>" value="<?= DeckSpec::DEFAULT_SLIDES ?>">
                        </div>
                    </div>
                </div>

                <!-- Options n8n : instance, clé API, tag, protection des webhooks -->
                <div id="cOptsN8n" style="display:none;margin-top:14px">
                    <?= $n8nHelp() ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px">
                        <div class="form-group" style="margin:0;flex:1;min-width:260px">
                            <label><?= te('URL de l\'instance n8n') ?> <?= $tip(t('L\'adresse racine, sans chemin. Un copier-coller de la barre d\'adresse (/home/workflows, /api/v1…) est nettoyé automatiquement. Le serveur MIA doit pouvoir la joindre.')) ?></label>
                            <input type="text" name="config_n8n_base_url" class="form-input" placeholder="<?= te('https://n8n.exemple.fr') ?>">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Tag d\'exposition') ?> <?= $tip(t('Seuls les workflows ACTIFS portant ce tag sont visibles de l\'assistant.')) ?></label>
                            <input type="text" name="config_n8n_tag" class="form-input" value="<?= htmlspecialchars(N8nService::DEFAULT_TAG) ?>" placeholder="<?= htmlspecialchars(N8nService::DEFAULT_TAG) ?>">
                        </div>
                        <div class="form-group" style="margin:0;flex:1;min-width:240px">
                            <label><?= te('Clé API n8n') ?> <?= $tip(t('Stockée chiffrée, jamais réaffichée. Sert uniquement à lister et décrire les workflows.')) ?></label>
                            <input type="password" name="api_key" class="form-input" autocomplete="new-password" placeholder="n8n_api_…">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                        <div class="form-group" style="margin:0;flex:0 0 220px">
                            <label><?= te('En-tête d\'authentification') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel)') ?></span> <?= $tip(t('Un webhook n8n est un point d\'entrée PUBLIC. Protégez-le côté n8n (nœud Webhook → Authentication → Header Auth) et recopiez ici le même en-tête. La clé API n\'est jamais envoyée aux webhooks.')) ?></label>
                            <input type="text" name="config_n8n_header_name" class="form-input" placeholder="X-Mia-Token">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 240px">
                            <label><?= te('Valeur de l\'en-tête') ?></label>
                            <input type="password" name="config_n8n_header_value" class="form-input" autocomplete="new-password" placeholder="<?= te('secret partagé') ?>">
                        </div>
                        <div class="form-group" style="margin:0;display:flex;align-items:center;gap:10px;padding-bottom:6px">
                            <span class="switch">
                                <input type="checkbox" name="config_n8n_auto_read" value="1" id="cN8nAutoRead">
                                <span class="track"></span>
                            </span>
                            <label for="cN8nAutoRead" style="margin:0;font-weight:500"><?= te('Exécution directe des workflows « {tag} »', ['tag' => N8nService::DEFAULT_TAG . N8nService::READ_SUFFIX]) ?> <?= $tip(t('Par défaut, CHAQUE exécution passe par une carte d\'approbation dans la conversation. Cochez pour dispenser d\'approbation les workflows portant AUSSI le tag de lecture — à réserver aux workflows strictement en lecture.'), true) ?></label>
                        </div>
                    </div>
                </div>

                <!-- Options Gamma : clé API + réglages de génération -->
                <div id="cOptsGamma" style="display:none">
                    <?= $gammaHelp() ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
                        <div class="form-group" style="margin:0;flex:1;min-width:260px">
                            <label><?= te('Clé API Gamma') ?> <?= $tip(t('Créée dans gamma.app → paramètres du compte → API keys (plan Pro+ requis). Stockée chiffrée, jamais réaffichée.')) ?></label>
                            <input type="password" name="api_key" class="form-input" autocomplete="new-password" placeholder="sk-gamma-…">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Thème') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel)') ?></span> <?= $tip(t('Identifiant d\'un thème du workspace Gamma. Vide = thème par défaut du compte. Le bouton Tester signale un thème introuvable.')) ?></label>
                            <input type="text" name="config_gamma_theme_id" class="form-input" placeholder="<?= te('Vide = thème du compte') ?>">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 150px">
                            <label><?= te('Plafond de slides') ?> <?= $tip(t('Nombre maximum de slides qu\'une génération peut demander (1 à 75 selon le plan Gamma). L\'assistant ne peut pas le dépasser.'), true) ?></label>
                            <input type="number" name="config_gamma_max_cards" class="form-input" value="<?= GammaService::DEFAULT_MAX_CARDS ?>" min="1" max="75">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                        <div class="form-group" style="margin:0;flex:0 0 220px">
                            <label><?= te('Images des slides') ?> <?= $tip(t('Source des illustrations générées par gamma.app.')) ?></label>
                            <select name="config_gamma_image_source" class="form-select">
                                <option value="aiGenerated" selected><?= te('Générées par IA') ?></option>
                                <option value="pictographic"><?= te('Pictogrammes') ?></option>
                                <option value="pexels"><?= te('Photos libres (Pexels)') ?></option>
                                <option value="noImages"><?= te('Sans image') ?></option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Export automatique') ?> <?= $tip(t('Gamma produit en plus un fichier téléchargeable à chaque génération (lien valable environ une semaine).')) ?></label>
                            <select name="config_gamma_export_as" class="form-select">
                                <option value="" selected><?= te('Aucun') ?></option>
                                <option value="pptx">PowerPoint (PPTX)</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:1;min-width:260px">
                            <label><?= te('Consignes par défaut') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel)') ?></span> <?= $tip(t('Ton, charte, marque… ajoutées DEVANT les instructions de l\'assistant à chaque génération.'), true) ?></label>
                            <textarea name="config_gamma_default_instructions" class="form-input" rows="2" placeholder="<?= te('ex : Ton institutionnel, charte bleu marine, logo en dernière slide.') ?>"></textarea>
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px">
                    <button type="button" class="btn-primary" data-step-next="3" style="font-size:13px;padding:8px 18px"><?= te('Continuer') ?></button>
                </div>
                </div>
            </div>

            <!-- ÉTAPE 3 — FINALISATION -->
            <div class="tool-step" data-step="3">
                <button type="button" class="step-head" aria-expanded="false">
                    <span class="step-title"><?= te('Nommez et activez') ?></span>
                    <span class="step-summary"></span>
                    <span class="step-chevron" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
                </button>
                <div class="step-body">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                    <div class="form-group" style="margin:0;flex:1;min-width:200px">
                        <label><?= te('Nom affiché') ?> <?= $tip(t('Le nom du bouton que verront vos utilisateurs dans le chat. Soyez clair : « Image réaliste », « Mini-site HTML ».')) ?></label>
                        <input type="text" name="name" class="form-input" placeholder="<?= te('ex : Image réaliste, Mini-site HTML') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0;flex:1;min-width:220px" id="cApiKeyWrap">
                        <label><?= te('Clé API dédiée') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel)') ?></span> <?= $tip(t('Une clé propre à cet outil. Laissez vide pour réutiliser la clé globale du fournisseur (Modèles IA).'), true) ?></label>
                        <input type="password" name="api_key" class="form-input" placeholder="<?= te('Vide = clé globale du fournisseur') ?>">
                    </div>
                    <div class="form-group" style="margin:0;flex:0 0 90px">
                        <label><?= te('Ordre') ?> <?= $tip(t('Position dans la liste proposée à l\'utilisateur. Petit nombre = affiché en premier.'), true) ?></label>
                        <input type="number" name="sort_order" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-group" style="margin:0;display:flex;align-items:center;gap:10px;padding-bottom:6px">
                        <span class="switch">
                            <input type="checkbox" name="is_active" value="1" id="cActive" checked>
                            <span class="track"></span>
                        </span>
                        <label for="cActive" style="margin:0;font-weight:500"><?= te("Actif — proposé aux groupes qui l'autorisent") ?> <?= $tip(t('Décoché = l\'outil est enregistré mais masqué pour tous. Coché = proposé dans le chat aux membres des groupes qui l\'autorisent, et aux administrateurs. Un nouvel outil n\'est autorisé dans aucun groupe : cochez-le ensuite dans Personnes → Groupes → Droits d\'accès → Outils.'), true) ?></label>
                    </div>
                </div>
                <div style="margin-top:18px">
                    <button type="submit" class="btn-primary" style="padding:9px 20px"><?= te('Ajouter l\'outil') ?></button>
                </div>
                </div>
            </div>
        </form>
    </div>

    <!-- ======================================================= -->
    <!-- CARTES DES OUTILS                                        -->
    <!-- (même langage visuel que /admin/connectors)              -->
    <!-- La grille est TOUJOURS rendue : elle contient au minimum -->
    <!-- la carte « Dictée vocale » (outil intégré) et la carte   -->
    <!-- fantôme « Ajouter un outil ».                            -->
    <!-- ======================================================= -->
    <?php
        // Icône par type — les mêmes pictos que les cartes de choix du
        // formulaire de création : l'admin retrouve d'un coup d'œil ce qu'il
        // a créé.
        $typeIcons = [
            'image'            => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
            'video'            => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="14" height="14" rx="2"/><path d="m22 8-6 4 6 4V8z"/></svg>',
            'html'             => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'html_illustrated' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="9" r="1.6"/><path d="m21 16-4.5-4.5L9 19"/></svg>',
            'map'              => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21"/><line x1="8" y1="3" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="21"/></svg>',
            'presentation'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg>',
            'n8n'              => '<img src="/assets/img/n8n.svg" width="22" height="22" alt="">',
            'gamma'            => '<img src="/assets/img/gamma.svg" width="22" height="22" alt="">',
        ];
        // Couleur d'accent par type (liseré, tuile d'icône, survol) — mêmes
        // teintes que les badges de type, pour une identité visuelle par outil.
        $typeAccents = [
            'image'            => 'var(--accent)',
            'video'            => '#f87171',
            'html'             => '#818cf8',
            'html_illustrated' => '#818cf8',
            'map'              => '#34d399',
            'presentation'     => '#fbbf24',
            'n8n'              => '#ea4b71',
            'gamma'            => '#a78bfa',
        ];
    ?>
    <?php
        // Dictée vocale : outil INTÉGRÉ (clé Settings, pas une ligne chat_tools).
        // Rendue comme une carte de la grille, au même langage visuel que les
        // outils configurés — son interrupteur enregistre à la volée en AJAX
        // sur POST /admin/tools/builtin (JSON), fallback flash+redirect sans JS.
        $micEnabled   = Settings::get('mic_enabled', '1') === '1';
        // Conversation vocale : second outil intégré, même mécanique, sa
        // propre clé (SpeechSynthesizer::SETTING_ENABLED). Les deux cartes
        // portent leur clé en data-builtin-key : le script poste CETTE clé
        // seule, et le handler ne touche qu'aux clés présentes dans le POST.
        $voiceChatEnabled = SpeechSynthesizer::isEnabled();
        // Tâches programmées : troisième carte intégrée, même mécanique. Aucun
        // prérequis de clé — elles passent par les modèles déjà configurés.
        $schedulesEnabled = ScheduledTask::isEnabled();
        // Option de la même carte : le mode « Agir sans validation » des tâches.
        $schedulesAuto    = ScheduledTask::autoAllowed();
        // Workflows : quatrième carte intégrée, même mécanique (créateurs et
        // administrateurs seulement), avec son option « Agir sans validation ».
        $workflowsEnabled = Workflow::isEnabled();
        $workflowsAuto    = Workflow::autoAllowed();
        $workflowsLoopMax = Workflow::loopMax();
        // Suggestions de suite (FollowUpSuggestion) : cinquième carte intégrée,
        // même mécanique. Aucun prérequis : modèle de la conversation.
        $suggestionsEnabled = FollowUpSuggestion::isEnabled();
        $hasOpenAiKey = isset($providersWithKey['openai']);
        // Lien vers les clés des fournisseurs, posé APRÈS l'échappement des
        // phrases qui le citent ({lien}) : la phrase reste entière au dictionnaire.
        $llmsLink     = '<a href="/admin/llms">' . te('Gestion LLMs') . '</a>';
        $toolCount    = count($tools) + 5; // + dictée vocale, conversation vocale, suggestions de suite, tâches programmées et workflows intégrés
    ?>
    <div class="tools-list-head">
        <h2><?= te('Outils disponibles') ?></h2>
        <span class="tools-list-count"><?= htmlspecialchars(I18n::plural($toolCount, '{n} outil', '{n} outils')) ?></span>
        <p class="tools-list-hint">
            <?= teb('{b}Actif{/b} = proposé dans le chat aux membres des {b}groupes{/b} qui l\'autorisent (Personnes → Groupes → Droits d\'accès → Outils), et aux administrateurs. {b}Inactif{/b} = conservé ici, mais invisible de tous.') ?>
        </p>
    </div>
    <div class="connector-grid tools-grid">

            <!-- Carte outil intégré : Dictée vocale (micro) -->
            <div class="connector-card tool-card tool-card-builtin<?= (!$hasOpenAiKey || !$micEnabled) ? ' is-off' : '' ?>"
                 id="builtinMicCard"
                 data-builtin-key="mic_enabled"
                 style="--tool-accent:#2dd4bf">
                <div class="connector-top">
                    <div class="connector-icon" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="3" width="6" height="12" rx="3"/>
                            <path d="M5 11a7 7 0 0 0 14 0"/>
                            <line x1="12" y1="18" x2="12" y2="22"/>
                            <line x1="8" y1="22" x2="16" y2="22"/>
                        </svg>
                    </div>
                    <div class="connector-title">
                        <h3><?= te('Dictée vocale') ?></h3>
                        <div class="connector-caps">
                            <span class="tool-type-chip"><?= te('Intégré') ?></span>
                            <span class="badge badge-gray">OpenAI · Whisper</span>
                        </div>
                    </div>
                    <div class="connector-switch">
                        <?php if ($hasOpenAiKey): ?>
                            <span class="tool-state" title="<?= te('Actif = le bouton micro est proposé à tous les utilisateurs dans le chat.') ?>">
                                <span class="tool-state-label"><?= $micEnabled ? te('Actif') : te('Inactif') ?></span>
                                <span class="tool-state-sub"><?= $micEnabled ? te('Utilisable par tous') : te('Masqué du chat') ?></span>
                            </span>
                            <label class="ios-switch">
                                <input type="checkbox" class="tool-active-toggle"
                                       <?= $micEnabled ? 'checked' : '' ?>
                                       aria-label="<?= te('Rendre la dictée vocale utilisable par tous les utilisateurs') ?>">
                                <span class="ios-switch-slider"></span>
                            </label>
                        <?php else: ?>
                            <span class="badge badge-yellow"><?= te('Clé requise') ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="connector-meta">
                    <span><strong><?= te('Micro dans la zone de message') ?></strong></span>
                    <span class="connector-dot">•</span>
                    <span><?= te('parole transcrite en texte, en temps réel') ?></span>
                </div>

                <?php if (!$hasOpenAiKey): ?>
                    <p class="connector-warn">⚠ <?= str_replace('{lien}', $llmsLink, te('Renseignez la clé API OpenAI dans {lien} pour activer la dictée vocale.')) ?></p>
                <?php endif; ?>

                <p class="connector-note tool-no-trigger">
                    <?= teb('Outil {b}intégré{/b} à l\'application : rien à configurer, rien à tester. Activé, le bouton micro apparaît dans la zone de saisie de tout le monde.') ?>
                </p>
            </div>

            <!-- Carte outil intégré : Conversation vocale (à côté du micro) -->
            <div class="connector-card tool-card tool-card-builtin<?= (!$hasOpenAiKey || !$voiceChatEnabled) ? ' is-off' : '' ?>"
                 id="builtinVoiceChatCard"
                 data-builtin-key="<?= htmlspecialchars(SpeechSynthesizer::SETTING_ENABLED) ?>"
                 style="--tool-accent:#0ea5e9">
                <div class="connector-top">
                    <div class="connector-icon" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 14v-3a8 8 0 0 1 16 0v3"/>
                            <rect x="2" y="13" width="5" height="7" rx="2"/>
                            <rect x="17" y="13" width="5" height="7" rx="2"/>
                            <path d="M19.5 20v1a2 2 0 0 1-2 2H13"/>
                        </svg>
                    </div>
                    <div class="connector-title">
                        <h3><?= te('Conversation vocale') ?></h3>
                        <div class="connector-caps">
                            <span class="tool-type-chip"><?= te('Intégré') ?></span>
                            <span class="badge badge-gray"><?= te('OpenAI · Realtime + synthèse vocale') ?></span>
                        </div>
                    </div>
                    <div class="connector-switch">
                        <?php if ($hasOpenAiKey): ?>
                            <span class="tool-state" title="<?= te('Actif = le bouton de conversation vocale est proposé à tous les utilisateurs, à côté du micro.') ?>">
                                <span class="tool-state-label"><?= $voiceChatEnabled ? te('Actif') : te('Inactif') ?></span>
                                <span class="tool-state-sub"><?= $voiceChatEnabled ? te('Utilisable par tous') : te('Masqué du chat') ?></span>
                            </span>
                            <label class="ios-switch">
                                <input type="checkbox" class="tool-active-toggle"
                                       <?= $voiceChatEnabled ? 'checked' : '' ?>
                                       aria-label="<?= te('Rendre la conversation vocale utilisable par tous les utilisateurs') ?>">
                                <span class="ios-switch-slider"></span>
                            </label>
                        <?php else: ?>
                            <span class="badge badge-yellow"><?= te('Clé requise') ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="connector-meta">
                    <span><strong><?= te('Bouton à côté du micro') ?></strong></span>
                    <span class="connector-dot">•</span>
                    <span><?= te('l\'utilisateur parle, MIA répond et lit sa réponse à voix haute') ?></span>
                </div>

                <?php if (!$hasOpenAiKey): ?>
                    <p class="connector-warn">⚠ <?= str_replace('{lien}', $llmsLink, te('Renseignez la clé API OpenAI dans {lien} pour activer la conversation vocale.')) ?></p>
                <?php endif; ?>

                <p class="connector-note tool-no-trigger">
                    <?= $balises(teb('Outil {b}intégré{/b} : rien à configurer, rien à tester. Chaque tour passe par l\'assistant, les bases et les connecteurs de la conversation, comme un message écrit. Facturé à la minute écoutée (ligne {i}Dictée{/i}) et à la minute lue (ligne {i}Lecture à voix haute{/i}) de Tarifs & crédits, dans le quota de l\'utilisateur.')) ?>
                </p>
            </div>
            <!-- Carte outil intégré : Suggestions de suite (zone de saisie, Tab) -->
            <div class="connector-card tool-card tool-card-builtin<?= $suggestionsEnabled ? '' : ' is-off' ?>"
                 id="builtinSuggestionsCard"
                 data-builtin-key="<?= htmlspecialchars(FollowUpSuggestion::SETTING_ENABLED) ?>"
                 style="--tool-accent:#f59e0b">
                <div class="connector-top">
                    <div class="connector-icon" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 6h11"/>
                            <path d="M4 11h7"/>
                            <path d="M4 16h5" stroke-dasharray="1.6 2.4"/>
                            <path d="M15 13l3 3-3 3"/>
                            <path d="M18 16h-5"/>
                            <path d="M21 5v14"/>
                        </svg>
                    </div>
                    <div class="connector-title">
                        <h3><?= te('Suggestions de suite') ?></h3>
                        <div class="connector-caps">
                            <span class="tool-type-chip"><?= te('Intégré') ?></span>
                            <span class="badge badge-gray"><?= te('Modèle de la conversation') ?></span>
                        </div>
                    </div>
                    <div class="connector-switch">
                        <span class="tool-state" title="<?= te('Actif = après chaque réponse, la zone de saisie propose la suite logique, reprise d\'un appui sur Tab.') ?>">
                            <span class="tool-state-label"><?= $suggestionsEnabled ? te('Actif') : te('Inactif') ?></span>
                            <span class="tool-state-sub"><?= $suggestionsEnabled ? te('Utilisable par tous') : te('Masqué du chat') ?></span>
                        </span>
                        <label class="ios-switch">
                            <input type="checkbox" class="tool-active-toggle"
                                   <?= $suggestionsEnabled ? 'checked' : '' ?>
                                   aria-label="<?= te('Proposer une suggestion de suite après chaque réponse, à tous les utilisateurs') ?>">
                            <span class="ios-switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="connector-meta">
                    <span><strong><?= te('Dans la zone de saisie') ?></strong></span>
                    <span class="connector-dot">•</span>
                    <span><?= te('la suite logique de la réponse, reprise d\'un appui sur Tab') ?></span>
                </div>

                <p class="connector-note tool-no-trigger">
                    <?= $balises(teb('Outil {b}intégré{/b} : rien à configurer. Une suggestion par réponse, calculée par le modèle de la conversation et gardée avec elle — quelques centaines de tokens, ligne {i}Suggestions de suite{/i} de la Consommation, dans le quota de l\'utilisateur. Rien n\'est envoyé sans que la personne la relise et valide.')) ?>
                </p>
            </div>
            <!-- Carte outil intégré : Tâches programmées (Espaces → Tâches programmées) -->
            <div class="connector-card tool-card tool-card-builtin<?= $schedulesEnabled ? '' : ' is-off' ?>"
                 id="builtinSchedulesCard"
                 data-builtin-key="<?= htmlspecialchars(ScheduledTask::SETTING_ENABLED) ?>"
                 style="--tool-accent:#a855f7">
                <div class="connector-top">
                    <div class="connector-icon" aria-hidden="true">
                        <?= ScheduledTask::icon(22) ?>
                    </div>
                    <div class="connector-title">
                        <h3><?= te('Tâches programmées') ?></h3>
                        <div class="connector-caps">
                            <span class="tool-type-chip"><?= te('Intégré') ?></span>
                            <span class="badge badge-gray"><?= te('Planificateur du worker') ?></span>
                        </div>
                    </div>
                    <div class="connector-switch">
                        <span class="tool-state" title="<?= te('Actif = chacun peut programmer des demandes récurrentes dans ses paramètres.') ?>">
                            <span class="tool-state-label"><?= $schedulesEnabled ? te('Actif') : te('Inactif') ?></span>
                            <span class="tool-state-sub"><?= $schedulesEnabled ? te('Utilisable par tous') : te('Masqué du chat') ?></span>
                        </span>
                        <label class="ios-switch">
                            <input type="checkbox" class="tool-active-toggle"
                                   <?= $schedulesEnabled ? 'checked' : '' ?>
                                   aria-label="<?= te('Proposer les tâches programmées à tous les utilisateurs') ?>">
                            <span class="ios-switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="connector-meta">
                    <span><strong><?= te('Espaces → Tâches programmées') ?></strong></span>
                    <span class="connector-dot">•</span>
                    <span><?= te('une demande rejouée à date fixe, réponse dans sa conversation') ?></span>
                </div>

                <?php /* Option de la carte, enregistrée à la volée comme l'interrupteur
                         (sa propre clé, data-builtin-key sur la case). */ ?>
                <label class="tool-builtin-option">
                    <input type="checkbox" class="tool-builtin-option-toggle"
                           data-builtin-key="<?= htmlspecialchars(ScheduledTask::SETTING_AUTO) ?>"
                           <?= $schedulesAuto ? 'checked' : '' ?>>
                    <span>
                        <strong><?= te('Proposer « Agir sans validation »') ?></strong>
                        <span class="tool-builtin-option-hint">
                            <?= te('Une personne peut laisser une tâche effectuer elle-même ses actions — évènements, tâches, messages Slack, fichiers, workflows, et les emails si elle coche leur envoi — sans carte à approuver, {n} au plus par exécution. Décoché, ces tâches préparent leurs actions, qui attendent sa validation.', ['n' => (int)ScheduledTask::AUTO_ACTIONS_MAX]) ?>
                        </span>
                    </span>
                    <span class="tool-builtin-option-state" aria-live="polite"></span>
                </label>

                <p class="connector-note tool-no-trigger">
                    <?= teb('Outil {b}intégré{/b} : chaque exécution est un message ordinaire, envoyé par le planificateur du worker — même assistant, mêmes sources, dans le quota de la personne. Lecture seule par défaut ; la personne choisit, tâche par tâche, de préparer des actions à approuver ou — si l\'option ci-dessus le permet — d\'agir sans validation. {n} tâches actives au plus par personne ; désactivé, plus aucune ne s\'exécute (elles restent enregistrées).', ['n' => (int)ScheduledTask::MAX_ACTIVE_PER_USER]) ?>
                </p>
            </div>
            <!-- Carte outil intégré : Workflows (/workflows, créateurs et administrateurs) -->
            <div class="connector-card tool-card tool-card-builtin<?= $workflowsEnabled ? '' : ' is-off' ?>"
                 id="builtinWorkflowsCard"
                 data-builtin-key="<?= htmlspecialchars(Workflow::SETTING_ENABLED) ?>"
                 style="--tool-accent:#10a37f">
                <div class="connector-top">
                    <div class="connector-icon" aria-hidden="true">
                        <?= Workflow::icon(22) ?>
                    </div>
                    <div class="connector-title">
                        <h3><?= te('Workflows') ?></h3>
                        <div class="connector-caps">
                            <span class="tool-type-chip"><?= te('Intégré') ?></span>
                            <span class="badge badge-gray"><?= te('Créateurs et administrateurs') ?></span>
                        </div>
                    </div>
                    <div class="connector-switch">
                        <span class="tool-state" title="<?= te('Actif = créateurs et administrateurs composent et lancent des workflows.') ?>">
                            <span class="tool-state-label"><?= $workflowsEnabled ? te('Actif') : te('Inactif') ?></span>
                            <?php /* data-on : la portée relue par le script quand l'interrupteur bascule. */ ?>
                            <span class="tool-state-sub" data-on="<?= te('Créateurs et admins') ?>"><?= $workflowsEnabled ? te('Créateurs et admins') : te('Masqué') ?></span>
                        </span>
                        <label class="ios-switch">
                            <input type="checkbox" class="tool-active-toggle"
                                   <?= $workflowsEnabled ? 'checked' : '' ?>
                                   aria-label="<?= te('Proposer les workflows aux créateurs et administrateurs') ?>">
                            <span class="ios-switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="connector-meta">
                    <span><strong><?= te('Volet de gauche → Workflows') ?></strong></span>
                    <span class="connector-dot">•</span>
                    <span><?= te('des étapes qui se passent des pièces, des validations, un livrable') ?></span>
                </div>

                <label class="tool-builtin-option">
                    <input type="checkbox" class="tool-builtin-option-toggle"
                           data-builtin-key="<?= htmlspecialchars(Workflow::SETTING_AUTO) ?>"
                           <?= $workflowsAuto ? 'checked' : '' ?>>
                    <span>
                        <strong><?= te('Proposer « Agir sans validation » dans les workflows') ?></strong>
                        <span class="tool-builtin-option-hint">
                            <?= te('Une étape ou une action peut alors agir seule dans les outils reliés (évènements, tâches, messages, fichiers), et envoyer des emails si la personne le coche. Décoché, tout ce qu\'un workflow prépare attend sa validation.') ?>
                        </span>
                    </span>
                    <span class="tool-builtin-option-state" aria-live="polite"></span>
                </label>

                <label class="tool-builtin-option tool-builtin-option-select">
                    <span>
                        <strong><?= te('Éléments traités au plus par une boucle « pour chaque »') ?></strong>
                        <span class="tool-builtin-option-hint">
                            <?= te('Une boucle répète ses briques pour chaque ligne d\'un Excel, chaque pièce jointe, chaque fichier… Chaque élément est un vrai travail (crédits, temps) : au-delà de ce nombre, les éléments suivants sont signalés, pas traités.') ?>
                        </span>
                    </span>
                    <select class="tool-builtin-option-choice" data-builtin-key="<?= htmlspecialchars(Workflow::SETTING_LOOP_MAX) ?>"
                            aria-label="<?= te('Éléments traités au plus par une boucle') ?>">
                        <?php foreach (Workflow::LOOP_MAX_CHOICES as $n): ?>
                            <option value="<?= (int)$n ?>"<?= $workflowsLoopMax === $n ? ' selected' : '' ?>><?= (int)$n ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="tool-builtin-option-state" aria-live="polite"></span>
                </label>

                <p class="connector-note tool-no-trigger">
                    <?= teb('Outil {b}intégré{/b} : chaque étape est un message ordinaire mené par MIA ou un assistant, dans le quota de la personne qui lance le workflow. Seule la version publiée s\'exécute ; {n} workflows au plus par personne. Désactivé, plus rien ne démarre : les workflows restent enregistrés, une exécution en cours se met en pause à sa prochaine étape.', ['n' => (int)Workflow::MAX_PER_USER]) ?>
                </p>
            </div>
            <?php foreach ($tools as $tool):
                $cfg = json_decode($tool['config_json'] ?? '{}', true) ?: [];
                $cfgParts = [];
                if ($tool['type'] === 'image') {
                    if (!empty($cfg['size']))         $cfgParts[] = $cfg['size'];
                    if (!empty($cfg['quality']))      $cfgParts[] = ucfirst($cfg['quality']);
                    if (!empty($cfg['aspect_ratio'])) $cfgParts[] = $cfg['aspect_ratio'];
                    // Rien de figé : c'est l'état normal depuis que taille et
                    // qualité se décident à la génération. Un « — » laisserait
                    // croire à une configuration manquante.
                    if ($cfgParts === []) $cfgParts[] = t('taille et qualité à la génération');
                } elseif ($tool['type'] === 'html' || $tool['type'] === 'html_illustrated' || $tool['type'] === 'map' || $tool['type'] === 'presentation') {
                    if (isset($cfg['temperature']))   $cfgParts[] = 'temp ' . $cfg['temperature'];
                    if ($tool['type'] === 'html_illustrated') $cfgParts[] = (int)($cfg['max_images'] ?? 3) . ' img max';
                    if ($tool['type'] === 'presentation') $cfgParts[] = (int)($cfg['default_slides'] ?? DeckSpec::DEFAULT_SLIDES) . ' slides';
                } elseif ($tool['type'] === 'n8n') {
                    // Ni fournisseur ni modèle ici : ce qui identifie l'outil,
                    // c'est l'instance visée et le tag qui borne son périmètre.
                    $host = (string)(parse_url((string)($cfg['base_url'] ?? ''), PHP_URL_HOST) ?: '');
                    if ($host !== '')            $cfgParts[] = $host;
                    if (!empty($cfg['tag']))     $cfgParts[] = 'tag ' . $cfg['tag'];
                    if (!empty($cfg['auto_read'])) $cfgParts[] = t('lecture directe');
                } elseif ($tool['type'] === 'gamma') {
                    if (!empty($cfg['theme_id']))  $cfgParts[] = t('thème {id}', ['id' => (string)$cfg['theme_id']]);
                    if (!empty($cfg['export_as'])) $cfgParts[] = 'export ' . strtoupper((string)$cfg['export_as']);
                    $cfgParts[] = GammaService::sanitizeMaxCards($cfg['max_cards'] ?? null) . ' slides max';
                }
                $cfgStr = implode(', ', $cfgParts) ?: '—';
                $typeLabel  = $tool['type'] === 'html' ? 'HTML'
                    : ($tool['type'] === 'html_illustrated' ? t('Site illustré')
                    : ($tool['type'] === 'map' ? t('Carte')
                    : ($tool['type'] === 'presentation' ? t('Présentation')
                    : ($tool['type'] === 'n8n' ? 'n8n'
                    : ($tool['type'] === 'gamma' ? 'Gamma'
                    : ($tool['type'] === 'video' ? t('Vidéo') : t('Image')))))));
                $modelLabel = $imageModelLabels[$tool['model']] ?? $tool['model'];
                // Outil utilisable ? Ce qui manque (clé, URL n8n, outil image
                // source d'un site illustré) vient de la règle partagée.
                $tid      = (int)$tool['id'];
                $blockers = ChatTool::missingPrerequisites($tool, $providersWithKey, $toolById, $providerLabels);
                $usable   = $blockers === [];
                // Portée réelle : groupes ACTIFS qui l'autorisent. « Actif » seul
                // ne propose l'outil qu'aux administrateurs.
                $groupCount = (int)($toolGroupCounts[$tid] ?? 0);
                $reachLabel = $groupCount === 0
                    ? t('Aucun groupe — admins seuls')
                    : I18n::plural($groupCount, '{n} groupe', '{n} groupes');
                // Arrêt du modèle par son fournisseur (catalogue, `retirement`) :
                // annoncé = avertissement ; effectif = badge « Hors service ».
                $arret       = ChatTool::retirement($tool);
                $retired     = $arret !== null && $arret['retired'];
                $lastOk      = isset($tool['last_test_ok']) ? (int)$tool['last_test_ok'] : null;
                $lastMsg     = trim((string)($tool['last_test_message'] ?? ''));
                $lastDate    = trim((string)($tool['last_tested_at'] ?? ''));
                $testColor   = 'var(--text-muted)';
                $testText    = '';
                if ($lastOk === 1) {
                    $testColor = '#10a37f'; // lisible sur thème sombre ET clair
                    $testText  = 'OK';
                } elseif ($lastOk === 0) {
                    $testColor = '#ef4444';
                    $testText  = t('Erreur');
                }
                if ($testText !== '' && $lastDate !== '') {
                    $testText .= ' - ' . $lastDate;
                }
                if ($testText !== '' && $lastMsg !== '') {
                    $testText .= ' - ' . mb_substr($lastMsg, 0, 140);
                }
            ?>
            <?php
                // Règle de suggestion couvrant ce type (null pour n8n/gamma :
                // capacités d'assistant, jamais suggérées pendant la frappe).
                $rule         = ToolTriggers::ruleForType((string)$tool['type']);
                $ruleTriggers = $rule !== null ? ToolTriggers::triggers($rule) : [];
                $ruleCustom   = $rule !== null && ToolTriggers::triggersAreCustom($rule);
                $ruleLabel    = $rule !== null ? ToolTriggers::label($rule) : '';
            ?>
            <div class="connector-card tool-card<?= (!$usable || !$tool['is_active'] || $retired) ? ' is-off' : '' ?>"
                 data-tool-id="<?= (int)$tool['id'] ?>"
                 <?= $retired ? 'data-retired="1"' : '' ?>
                 <?= $rule !== null ? 'data-trigger-rule="' . htmlspecialchars($rule) . '"' : '' ?>
                 style="--tool-accent:<?= htmlspecialchars($typeAccents[$tool['type']] ?? 'var(--accent)') ?>">
                <div class="connector-top">
                    <div class="connector-icon" aria-hidden="true"><?= $typeIcons[$tool['type']] ?? $typeIcons['image'] ?></div>
                    <div class="connector-title">
                        <h3><?= htmlspecialchars($tool['name']) ?></h3>
                        <div class="connector-caps">
                            <span class="tool-type-chip"><?= htmlspecialchars($typeLabel) ?></span>
                            <?php if (ChatTool::isBetaType((string)$tool['type'])): ?>
                                <span class="tool-beta-chip" title="<?= htmlspecialchars(t(ChatTool::BETA_HINT)) ?>"><?= htmlspecialchars(t(ChatTool::BETA_LABEL)) ?></span>
                            <?php endif; ?>
                            <?php if (!in_array($tool['type'], ['n8n', 'gamma'], true)): ?>
                                <span class="badge badge-gray"><?= htmlspecialchars($providerLabels[$tool['provider']] ?? $tool['provider']) ?></span>
                            <?php endif; ?>
                            <?php if ($retired): ?>
                                <span class="badge badge-red" title="<?= te('Modèle arrêté par son fournisseur : l\'outil est grisé dans le chat.') ?>"><?= te('Hors service') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="connector-switch">
                        <?php if ($usable): ?>
                            <span class="tool-state" title="<?= te('Actif = proposé dans le chat aux membres des groupes qui l\'autorisent (Personnes → Groupes → Droits d\'accès → Outils), et aux administrateurs.') ?>">
                                <span class="tool-state-label"><?= $tool['is_active'] ? te('Actif') : te('Inactif') ?></span>
                                <span class="tool-state-sub" data-on="<?= htmlspecialchars($reachLabel) ?>"><?= $tool['is_active'] ? htmlspecialchars($reachLabel) : te('Masqué du chat') ?></span>
                            </span>
                            <label class="ios-switch">
                                <input type="checkbox" class="tool-active-toggle"
                                       <?= $tool['is_active'] ? 'checked' : '' ?>
                                       aria-label="<?= te('Proposer « {nom} » dans le chat', ['nom' => (string)$tool['name']]) ?>">
                                <span class="ios-switch-slider"></span>
                            </label>
                        <?php else: ?>
                            <span class="badge badge-yellow"><?= te('Clé requise') ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="connector-meta">
                    <span><strong><?= htmlspecialchars($tool['type'] === 'n8n' ? 'Webhook' : ($tool['type'] === 'gamma' ? t('API Gamma') : $modelLabel)) ?></strong></span>
                    <?php if ($cfgStr !== '—'): ?>
                        <span class="connector-dot">•</span>
                        <span><?= htmlspecialchars($cfgStr) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($usable && $groupCount === 0): ?>
                    <p class="connector-warn" id="tool-no-group-<?= $tid ?>" <?= $tool['is_active'] ? '' : 'hidden' ?>>⚠ <?= str_replace(
                        '{lien}',
                        '<a href="/admin/user-groups">' . te('Personnes → Groupes') . '</a>',
                        te('Autorisé dans aucun groupe : seuls les administrateurs le voient. Cochez-le dans {lien} → Droits d\'accès → Outils.')
                    ) ?></p>
                <?php endif; ?>

                <?php if ($arret !== null): ?>
                    <?php
                        // Remède : un autre modèle quand le fournisseur en garde un en
                        // service, sinon rien d'autre à faire que suspendre l'outil.
                        $autreModele = ToolCatalog::offerableModels((string)$tool['type'], (string)$tool['provider']) !== [];
                    ?>
                    <p class="connector-warn tool-retirement">⚠
                        <?php if ($retired): ?>
                            <strong><?= te('Hors service depuis le {date}.', ['date' => Format::dateFr($arret['date'])]) ?></strong>
                        <?php else: ?>
                            <strong><?= te('Arrêt annoncé le {date}.', ['date' => Format::dateFr($arret['date'])]) ?></strong>
                        <?php endif; ?>
                        <?php /* Note du catalogue livré (config/tools_catalog.php) : son français est la clé. */ ?>
                        <?= htmlspecialchars(t($arret['note'])) ?>
                        <?php if ($retired): ?>
                            <?= $autreModele
                                ? teb('L\'outil est grisé dans le chat : choisissez un autre modèle dans {b}Modifier{/b}, ou désactivez-le.')
                                : te('L\'outil est grisé dans le chat : désactivez-le ou supprimez-le.') ?>
                        <?php else: ?>
                            <?= te('À cette date, l\'outil sera grisé dans le chat et ne produira plus rien.') ?>
                        <?php endif; ?>
                        <?php if ($arret['source_url'] !== ''): ?>
                            <a href="<?= htmlspecialchars($arret['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= te('Annonce du fournisseur') ?></a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($tool['type'] === 'presentation'): ?>
                    <p class="connector-warn">⚠ <?= teb("N'apparaît plus dans le chat : une présentation se demande dans la conversation, et {b}Modifier les slides{/b} sur sa carte ouvre l'éditeur (illustrations comprises). Les présentations déjà créées restent ouvrables. Cet outil peut être supprimé.") ?></p>
                <?php endif; ?>

                <?php if (!$usable): ?>
                    <?php
                        // Ce qui manque (ChatTool::missingPrerequisites) : deux éléments au plus.
                        $manques = $blockers;
                        $dernier = (string)array_pop($manques);
                        $manques = htmlspecialchars($manques === [] ? $dernier : t('{liste} et {dernier}', ['liste' => implode(', ', $manques), 'dernier' => $dernier]));
                    ?>
                    <p class="connector-warn">⚠ <?= in_array($tool['type'], ['n8n', 'gamma'], true)
                        ? str_replace('{manque}', $manques, te('Renseignez {manque}.'))
                        : str_replace(['{manque}', '{lien}'], [$manques, $llmsLink], te('Renseignez {manque} dans {lien}.')) ?></p>
                <?php endif; ?>

                <div class="tool-test-line" id="tool-test-result-<?= $tool['id'] ?>"
                     style="color:<?= htmlspecialchars($testColor) ?>"><?= htmlspecialchars($testText) ?></div>

                <?php if ($rule !== null): ?>
                <details class="connector-triggers tool-triggers">
                    <summary>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg>
                        <span><?= te('Expressions déclencheuses') ?></span>
                        <span class="connector-triggers-count"><?= count($ruleTriggers) ?></span>
                        <span class="connector-triggers-badge" <?= $ruleCustom ? '' : 'hidden' ?>><?= te('Personnalisées') ?></span>
                        <svg class="connector-triggers-chev" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <p class="connector-triggers-hint">
                        <?= teb('Quand un message en cours de frappe contient l\'une de ces expressions, MIA propose cet outil (bannière au-dessus de la saisie). {b}Une expression par ligne{/b} — ses mots doivent apparaître dans l\'ordre, d\'autres mots peuvent s\'intercaler dans la même phrase (« génère une image » reconnaît « génère-moi vite une image de chat »). Liste {b}commune à tous les outils {regle}{/b} ; vide = aucune suggestion.', ['regle' => $ruleLabel]) ?>
                    </p>
                    <textarea class="connector-triggers-input tool-triggers-input"
                              rows="5" spellcheck="false"
                              aria-label="<?= te('Expressions déclencheuses des outils {regle}', ['regle' => $ruleLabel]) ?>"><?= htmlspecialchars(implode("\n", $ruleTriggers)) ?></textarea>
                    <div class="connector-triggers-actions">
                        <button type="button" class="btn-secondary tool-triggers-reset"
                                title="<?= te('Revenir à la liste par défaut de l\'application') ?>"><?= te('Réinitialiser') ?></button>
                        <span class="connector-triggers-status" role="status" aria-live="polite"></span>
                    </div>
                </details>
                <?php elseif ($tool['type'] !== 'presentation'): ?>
                <p class="connector-note tool-no-trigger">
                    <?= teb('Pas de suggestion pendant la frappe : cette capacité est déclenchée par l\'{b}assistant{/b} lui-même, avec approbation dans la conversation.') ?>
                </p>
                <?php endif; ?>

                <div class="tool-card-actions">
                    <button type="button"
                            class="btn-secondary js-test-tool"
                            data-tool-id="<?= $tool['id'] ?>"
                            <?= !$toolsCan['edit'] ? 'disabled title="' . te('Votre rôle ne permet pas de tester les outils') . '"' : ($usable ? '' : 'disabled title="' . te('Renseignez la clé API pour pouvoir tester') . '"') ?>>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                        <?= te('Tester') ?>
                    </button>
                    <button type="button" class="btn-secondary"
                            onclick="openEditModal(<?= htmlspecialchars(json_encode($tool), ENT_QUOTES) ?>)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        <?= te('Modifier') ?>
                    </button>
                    <?php if ($toolsCan['delete']): ?>
                    <form method="POST" action="/admin/tools/<?= $tool['id'] ?>/delete"
                          onsubmit='return confirm(<?= tj('Supprimer cet outil ?') ?>)'>
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn-danger tool-del-btn" title="<?= te('Supprimer l\'outil') ?>" aria-label="<?= te('Supprimer « {nom} »', ['nom' => (string)$tool['name']]) ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Carte fantôme : second point d'entrée du formulaire d'ajout,
                 intégré à la grille (le bouton du header reste le premier). -->
            <?php if ($toolsCan['create']): ?>
            <button type="button" class="tool-add-card js-add-tool-open">
                <span class="tool-add-card-ic" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </span>
                <span class="tool-add-card-title"><?= te('Ajouter un outil') ?></span>
                <span class="tool-add-card-desc"><?= te('Image, vidéo, page web, carte, présentation ou automatisation n8n.') ?></span>
            </button>
            <?php endif; ?>
    </div>
</div>

<!-- =========================================================== -->
<!-- MODAL D'ÉDITION                                             -->
<!-- =========================================================== -->
<div class="modal" id="editModal" style="display:none">
    <div class="modal-box tool-modal">
        <!-- `data-autosave="off"` au repos : la modale est remplie par script,
             et une affectation programmatique peut émettre un `change`. Elle
             passe à « on » à la toute fin d'`openEditModal`, une fois tous les
             champs posés. `reload="close"` parce que la carte derrière porte le
             nom, le type et l'état de l'outil. -->
        <form method="POST" id="editForm" data-autosave="off" data-autosave-reload="close">
            <?= Csrf::field() ?>
            <input type="hidden" name="type" id="eTypeHidden">

            <!-- En-tête -->
            <div class="tool-modal-head">
                <span class="card-ic" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </span>
                <div style="flex:1;min-width:0">
                    <h3><?= te('Modifier l\'outil') ?></h3>
                    <p class="modal-subtitle" style="margin:2px 0 0"><?= te('Ajustez le moteur, les options et le statut.') ?></p>
                </div>
                <span class="badge badge-gray" id="eTypeDisplay" style="align-self:center"></span>
                <button type="button" class="tool-modal-x" id="cancelEditX" aria-label="<?= te('Fermer') ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <!-- Corps défilable -->
            <div class="tool-modal-body">

                <div class="form-group" style="margin:0">
                    <label><?= te('Nom affiché') ?></label>
                    <input type="text" name="name" id="eName" class="form-input" required>
                </div>

                <!-- Image -->
                <div id="eImageSection" class="tool-modal-group">
                    <div class="group-label"><?= te('Moteur image') ?></div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="form-group" style="margin:0;flex:0 0 180px">
                            <label><?= te('Fournisseur', [], 'llm') ?></label>
                            <select name="provider" id="eProviderImage" class="form-select">
                                <option value="openai">OpenAI (ChatGPT / DALL-E)</option>
                                <option value="gemini">Gemini (Nano Banana)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px" id="eModelImgOaiWrap">
                            <label><?= te('Modèle') ?></label>
                            <select name="model" id="eModelImgOai" class="form-select">
                                <?= $editModelOptions('image', 'openai') ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 220px;display:none" id="eModelImgGemWrap">
                            <label><?= te('Modèle') ?></label>
                            <select name="model" id="eModelImgGem" class="form-select" disabled>
                                <?= $editModelOptions('image', 'gemini') ?>
                            </select>
                        </div>
                    </div>
                    <!-- Taille et qualité dépendent du MODÈLE : les listes sont
                         peuplées par JS depuis le catalogue (OPENAI_IMAGE_MODEL_OPTIONS),
                         jamais écrites en dur ici — GPT Image 2.5 ajoute xhigh et max,
                         que GPT Image 2 refuse, et « standard »/« HD » ne valent que
                         pour DALL-E. -->
                    <div id="eOptsImgOai" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                        <div class="form-group" style="margin:0;flex:0 0 180px">
                            <label><?= te('Taille') ?></label>
                            <select name="config_size" id="eCfgSize" class="form-select"></select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 180px">
                            <label><?= te('Qualité') ?></label>
                            <select name="config_quality" id="eCfgQuality" class="form-select"></select>
                        </div>
                    </div>
                    <div id="eOptsImgOaiNative" style="display:none;margin-top:12px">
                        <div class="alert alert-success" style="margin:0;padding:10px 12px;font-size:12px">
                            <?= te('Ce modèle utilise la génération d\'image native de ChatGPT via Responses API (sans DALL-E).') ?>
                        </div>
                    </div>
                    <div id="eOptsImgImagen" style="display:none;margin-top:12px">
                        <div class="form-group" style="margin:0;display:inline-block">
                            <label><?= te('Format (aspect ratio)') ?></label>
                            <select name="config_aspect_ratio" id="eCfgAspect" class="form-select">
                                <option value="1:1">1:1</option>
                                <option value="16:9">16:9</option>
                                <option value="9:16">9:16</option>
                                <option value="4:3">4:3</option>
                                <option value="3:4">3:4</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:12px">
                        <label><?= te('Clé API dédiée') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel — vide = clé globale du fournisseur)') ?></span></label>
                        <div class="password-field">
                            <input type="password" name="api_key" id="eApiKey" data-autosave-reset class="form-input" placeholder="<?= te('Laisser vide pour ne pas changer') ?>">
                            <button type="button" class="password-toggle" aria-label="<?= te('Afficher') ?>" title="<?= te('Afficher / masquer') ?>">
                                <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 4.06-5.06"/><path d="M9.9 5.08A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 2.94"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M3 3l18 18"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- HTML -->
                <div id="eHtmlSection" class="tool-modal-group" style="display:none">
                    <div class="group-label"><?= te('Moteur et consignes (LLM)') ?></div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('LLM fournisseur') ?></label>
                            <select name="provider" id="eProviderHtml" class="form-select" disabled>
                                <?php foreach ($providerLabels as $pv => $pl): ?>
                                    <option value="<?= $pv ?>"><?= htmlspecialchars($pl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 240px">
                            <label><?= te('Modèle') ?></label>
                            <select name="model" id="eModelHtml" class="form-select" disabled></select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:12px">
                        <label><?= te('System prompt') ?></label>
                        <textarea name="config_system_prompt" id="eCfgSystemPrompt" class="form-input" rows="9"
                                  style="width:100%;resize:vertical;font-family:monospace;font-size:12px"></textarea>
                        <p class="tool-prompt-hint">
                            <?= te('Texte par défaut de ce type d\'outil, modifiable. Le format de sortie attendu par l\'affichage est ajouté automatiquement et n\'est pas éditable. Champ vidé = retour au texte par défaut.') ?>
                        </p>
                    </div>
                    <div class="form-group" style="margin-top:12px;flex:0 0 200px">
                        <label><?= te('Température') ?> <span id="eTempVal" style="color:var(--accent)">0.7</span></label>
                        <input type="range" name="config_temperature" id="eTemp"
                               min="0" max="1" step="0.1" value="0.7"
                               style="width:100%;accent-color:var(--accent)">
                    </div>
                </div>

                <!-- Site illustré -->
                <div id="eIllustratedSection" class="tool-modal-group" style="display:none">
                    <div class="group-label"><?= te('Illustrations') ?></div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div class="form-group" style="margin:0;flex:0 0 300px">
                            <label><?= te('Outil image pour les illustrations') ?></label>
                            <select name="config_image_tool_id" id="eImageToolId" class="form-select">
                                <option value=""><?= te('— Choisir un outil image —') ?></option>
                                <?php foreach ($imageTools as $it): ?>
                                    <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['name']) ?> (<?= htmlspecialchars((string)$it['provider'] . ' · ' . (string)$it['model']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 150px">
                            <label><?= te('Images max / page') ?></label>
                            <input type="number" name="config_max_images" id="eMaxImages" class="form-input" min="1" max="6" value="3">
                        </div>
                    </div>
                    <?= $renderStyleField('eImageStyle', 'eImageStyleCustom', '') ?>
                </div>

                <!-- Présentation -->
                <div id="ePresentationSection" class="tool-modal-group" style="display:none">
                    <div class="group-label"><?= te('Présentation') ?></div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div class="form-group" style="margin:0;flex:0 0 300px">
                            <label><?= te('Outil image pour les illustrations') ?></label>
                            <select name="config_image_tool_id" id="ePresImageToolId" class="form-select">
                                <option value=""><?= te('— Aucun (présentations sans image) —') ?></option>
                                <?php foreach ($imageTools as $it): ?>
                                    <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['name']) ?> (<?= htmlspecialchars((string)$it['provider'] . ' · ' . (string)$it['model']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 170px">
                            <label><?= te('Slides par défaut') ?></label>
                            <input type="number" name="config_default_slides" id="ePresDefaultSlides" class="form-input"
                                   min="<?= DeckSpec::MIN_SLIDES ?>" max="<?= DeckSpec::MAX_SLIDES ?>" value="<?= DeckSpec::DEFAULT_SLIDES ?>">
                        </div>
                    </div>
                </div>

                <!-- Vidéo -->
                <div id="eN8nSection" class="tool-modal-group" style="display:none">
                    <div class="group-label"><?= te('Instance n8n') ?></div>
                    <?= $n8nHelp() ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px">
                        <div class="form-group" style="margin:0;flex:1;min-width:240px">
                            <label><?= te('URL de l\'instance') ?></label>
                            <input type="text" name="config_n8n_base_url" id="eN8nBaseUrl" class="form-input" placeholder="<?= te('https://n8n.exemple.fr') ?>">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 180px">
                            <label><?= te('Tag d\'exposition') ?></label>
                            <input type="text" name="config_n8n_tag" id="eN8nTag" class="form-input" placeholder="<?= htmlspecialchars(N8nService::DEFAULT_TAG) ?>">
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:12px">
                        <label><?= te('Clé API n8n') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(vide = inchangée)') ?></span></label>
                        <input type="password" name="api_key" id="eN8nApiKey" data-autosave-reset class="form-input" autocomplete="new-password" placeholder="<?= te('Laisser vide pour ne pas changer') ?>">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                        <div class="form-group" style="margin:0;flex:0 0 220px">
                            <label><?= te('En-tête d\'authentification') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel)') ?></span></label>
                            <input type="text" name="config_n8n_header_name" id="eN8nHeaderName" class="form-input" placeholder="X-Mia-Token">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 240px">
                            <label><?= te('Valeur') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(vide = inchangée)') ?></span></label>
                            <input type="password" name="config_n8n_header_value" id="eN8nHeaderValue" data-autosave-reset class="form-input" autocomplete="new-password" placeholder="<?= te('Laisser vide pour ne pas changer') ?>">
                        </div>
                    </div>
                    <label class="n8n-inline-check" style="margin-top:10px">
                        <input type="checkbox" name="config_n8n_header_clear" value="1" id="eN8nHeaderClear">
                        <?= te('Supprimer la valeur de l\'en-tête') ?>
                    </label>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:12px">
                        <span class="switch">
                            <input type="checkbox" name="config_n8n_auto_read" value="1" id="eN8nAutoRead">
                            <span class="track"></span>
                        </span>
                        <label for="eN8nAutoRead" style="margin:0;font-weight:500"><?= str_replace('{tag}', '<code id="eN8nReadTag"></code>', te('Exécution directe des workflows de lecture (tag {tag})')) ?></label>
                    </div>
                    <p class="n8n-note">
                        <?= $balises(teb('Sans cette option, {b}chaque{/b} exécution passe par une carte d\'approbation dans la conversation. Avec, les workflows portant {i}aussi{/i} le tag de lecture partent directement — à réserver aux workflows strictement en lecture (consulter un stock, un statut).')) ?>
                    </p>
                </div>

                <div id="eGammaSection" class="tool-modal-group" style="display:none">
                    <div class="group-label"><?= te('Compte gamma.app') ?></div>
                    <?= $gammaHelp() ?>
                    <div class="form-group" style="margin-top:14px">
                        <label><?= te('Clé API Gamma') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(vide = inchangée)') ?></span></label>
                        <input type="password" name="api_key" id="eGammaApiKey" data-autosave-reset class="form-input" autocomplete="new-password" placeholder="<?= te('Laisser vide pour ne pas changer') ?>">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Thème') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel)') ?></span></label>
                            <input type="text" name="config_gamma_theme_id" id="eGammaThemeId" class="form-input" placeholder="<?= te('Vide = thème du compte') ?>">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 150px">
                            <label><?= te('Plafond de slides') ?></label>
                            <input type="number" name="config_gamma_max_cards" id="eGammaMaxCards" class="form-input" min="1" max="75" value="<?= GammaService::DEFAULT_MAX_CARDS ?>">
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Images des slides') ?></label>
                            <select name="config_gamma_image_source" id="eGammaImageSource" class="form-select">
                                <option value="aiGenerated"><?= te('Générées par IA') ?></option>
                                <option value="pictographic"><?= te('Pictogrammes') ?></option>
                                <option value="pexels"><?= te('Photos libres (Pexels)') ?></option>
                                <option value="noImages"><?= te('Sans image') ?></option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Export automatique') ?></label>
                            <select name="config_gamma_export_as" id="eGammaExportAs" class="form-select">
                                <option value=""><?= te('Aucun') ?></option>
                                <option value="pptx">PowerPoint (PPTX)</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:12px">
                        <label><?= te('Consignes par défaut') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel — ton, charte, marque)') ?></span></label>
                        <textarea name="config_gamma_default_instructions" id="eGammaInstructions" class="form-input" rows="2" placeholder="<?= te('ex : Ton institutionnel, charte bleu marine, logo en dernière slide.') ?>"></textarea>
                    </div>
                </div>

                <div id="eVideoSection" class="tool-modal-group" style="display:none">
                    <div class="group-label"><?= te('Moteur vidéo') ?></div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Fournisseur', [], 'llm') ?></label>
                            <select name="provider" id="eProviderVideo" class="form-select">
                                <?php foreach ($videoProviders as $pid => $pInfo): ?>
                                <option value="<?= htmlspecialchars((string)$pid) ?>"><?= htmlspecialchars((string)($pInfo['label'] ?? $pid)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;flex:0 0 200px">
                            <label><?= te('Modèle') ?></label>
                            <select name="model" id="eModelVideo" class="form-select">
                                <?php foreach (array_keys($videoProviders) as $pid): ?>
                                <?= $editModelOptions('video', (string)$pid) ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:12px">
                        <label><?= te('Clé API dédiée') ?> <span style="color:var(--text-muted);font-weight:400"><?= te('(optionnel — vide = clé globale du fournisseur)') ?></span></label>
                        <div class="password-field">
                            <input type="password" name="api_key" id="eVideoApiKey" data-autosave-reset class="form-input" placeholder="<?= te('Laisser vide pour ne pas changer') ?>">
                            <button type="button" class="password-toggle" aria-label="<?= te('Afficher') ?>" title="<?= te('Afficher / masquer') ?>">
                                <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 4.06-5.06"/><path d="M9.9 5.08A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 2.94"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M3 3l18 18"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Réglages -->
                <div class="tool-modal-group">
                    <div class="group-label"><?= te('Réglages') ?></div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end">
                        <div class="form-group" style="margin:0;flex:0 0 110px">
                            <label><?= te('Ordre') ?></label>
                            <input type="number" name="sort_order" id="eSortOrder" class="form-input" value="0" min="0">
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;padding-bottom:7px">
                            <span class="switch">
                                <input type="checkbox" name="is_active" value="1" id="eActive">
                                <span class="track"></span>
                            </span>
                            <label for="eActive" style="margin:0;font-weight:500"><?= te("Actif — proposé aux groupes qui l'autorisent") ?></label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pied collant -->
            <div class="tool-modal-foot">
                <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                <button type="button" class="btn-primary" id="cancelEdit"><?= te('Fermer') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
// ---- Libellés de la page, traduits côté serveur (tj) : posés une fois ----
const TOOLS_TXT = {
    // Badge de type de la fiche d'édition
    typeImage:        <?= tj('Image') ?>,
    typeVideo:        <?= tj('Vidéo') ?>,
    typeIllustrated:  <?= tj('Site illustré') ?>,
    typeMap:          <?= tj('Carte / Itinéraire') ?>,
    typePresentation: <?= tj('Présentation') ?>,
    typeN8n:          <?= tj('Automatisation n8n') ?>,
    typeGamma:        <?= tj('Présentation Gamma') ?>,
    // Résumé de l'étape 2 (Gamma)
    theme:            <?= tj('thème {id}') ?>,
    // Œil des champs de clé API
    show:             <?= tj('Afficher') ?>,
    hide:             <?= tj('Masquer') ?>,
    // Tests des outils
    testAll:          <?= tj('Tester tous les outils') ?>,
    testsRunning:     <?= tj('Tests en cours...') ?>,
    testPending:      <?= tj('En attente...') ?>,
    testRunning:      <?= tj('Test en cours...') ?>,
    testScheduled:    <?= tj('Test planifié...') ?>,
    testOk:           <?= tj('OK{duree} - {message}') ?>,
    testOkDefault:    <?= tj('Test réussi.') ?>,
    testFailed:       <?= tj('Erreur{duree} - {message}') ?>,
    testFailedDefault:<?= tj('Test échoué.') ?>,
    runProgress:      <?= tj('{fait}/{total} terminés...') ?>,
    runSummary:       <?= tj('{ok}/{total} OK') ?>,
    runSummaryFailed: <?= tj('{ok}/{total} OK, {n} en échec') ?>,
    badResponse:      <?= tj('Réponse serveur invalide') ?>,
    serverError:      <?= tj('Erreur serveur ({statut})') ?>,
    cannotStart:      <?= tj('Impossible de lancer les tests.') ?>,
    // Enregistrements à la volée
    saving:           <?= tj('Enregistrement…') ?>,
    saved:            <?= tj('Enregistré') ?>,
    savedCheck:       <?= tj('Enregistré ✓') ?>,
    defaultsRestored: <?= tj('Liste par défaut rétablie ✓') ?>,
    triggersFailed:   <?= tj('Échec — rien n\'a été enregistré.') ?>,
    triggersFailedPill: <?= tj('Expressions : échec, rien n\'a été enregistré.') ?>,
    active:           <?= tj('Actif') ?>,
    inactive:         <?= tj('Inactif') ?>,
    usableByAll:      <?= tj('Utilisable par tous') ?>,
    hiddenFromChat:   <?= tj('Masqué du chat') ?>,
    failed:           <?= tj('Échec') ?>,
    toolNotChanged:   <?= tj('Échec — l\'outil n\'a pas été modifié.') ?>,
    retry:            <?= tj('Échec — réessayez.') ?>,
    settingNotChanged:<?= tj('Échec — le réglage n\'a pas été modifié.') ?>,
    optionNotChanged: <?= tj('Échec — l\'option n\'a pas été modifiée.') ?>,
};
/** Remplace les {substituants} d'un libellé traduit (même règle que strtr côté serveur). */
function txt(cle, vars) {
    const s = String(TOOLS_TXT[cle] ?? cle);
    return vars ? s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : s;
}

// ---- Catalogue de modèles LLM injecté depuis PHP ----
const LLM_MODELS_CATALOG = <?= json_encode($allModels, JSON_UNESCAPED_UNICODE) ?>;
// Liste des modèles image OpenAI qui passent par /v1/images/generations (tous les autres → Responses API).
// Source : config/tools_catalog.php, clé `routing: 'images_generations'`.
const OPENAI_IMAGE_ENDPOINT_MODELS = new Set(<?= json_encode(ToolCatalog::modelsWithRouting('image', 'openai', 'images_generations'), JSON_UNESCAPED_UNICODE) ?>);
// Tailles et qualités acceptées PAR MODÈLE — source unique : le catalogue
// (ToolCatalog::modelOptions), le même que lit ImageGenerator avant d'appeler
// OpenAI. Deux listes divergentes proposeraient ici une valeur que l'API refuse.
<?php
$imageModelOptions = [];
foreach (array_keys(ToolCatalog::models('image', 'openai')) as $imgModelId) {
    $imageModelOptions[(string)$imgModelId] = [
        'sizes'     => ToolCatalog::modelOptions('image', 'openai', (string)$imgModelId, 'sizes'),
        'qualities' => ToolCatalog::modelOptions('image', 'openai', (string)$imgModelId, 'qualities'),
    ];
}
?>
const OPENAI_IMAGE_MODEL_OPTIONS = <?= json_encode($imageModelOptions, JSON_UNESCAPED_UNICODE) ?>;

/**
 * Libellés lisibles d'une taille ou d'une qualité (les valeurs restent celles
 * de l'API), traduits côté serveur. Le bloc qui va d'ici à la fin de
 * fillImageOptionSelect est exécuté tel quel sous node par ImageModelOptionsTest
 * (tj() rendus en français) : n'y mettre aucun autre PHP.
 */
const IMAGE_OPTION_TEXT = {
    labels: {
        auto: <?= tj('Automatique') ?>, low: <?= tj('Basse') ?>, medium: <?= tj('Moyenne') ?>, high: <?= tj('Haute') ?>,
        xhigh: <?= tj('Très haute') ?>, max: <?= tj('Maximale') ?>, standard: <?= tj('Standard') ?>, hd: 'HD',
    },
    automatic:   <?= tj('Automatique (défaut du modèle)') ?>,
    unsupported: <?= tj('{option} — non supportée par ce modèle') ?>,
};
const IMAGE_OPTION_LABELS = IMAGE_OPTION_TEXT.labels;
function imageOptionLabel(value) {
    if (IMAGE_OPTION_LABELS[value]) return IMAGE_OPTION_LABELS[value];
    return /^\d+x\d+$/.test(value) ? value.replace('x', '×') : value;
}

/**
 * Remplit une liste taille/qualité pour le modèle choisi. La valeur ENREGISTRÉE
 * est conservée même si le modèle ne l'accepte pas : elle y est ajoutée, signalée
 * et sélectionnée — sinon l'enregistrement automatique la remplacerait en silence
 * par la première de la liste (même leçon que showCurrentModel).
 */
function fillImageOptionSelect(sel, values, current) {
    if (!sel) return;
    const liste = Array.isArray(values) ? values : [];
    const valeur = String(current || '');
    // La valeur vient de la configuration enregistrée : échappée, comme tout ce
    // qui n'est pas écrit ici (le serveur la filtre déjà, on ne s'y fie pas).
    const esc = (v) => String(v).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    let html = `<option value="">${esc(IMAGE_OPTION_TEXT.automatic)}</option>`;
    liste.forEach(v => {
        html += `<option value="${esc(v)}">${esc(imageOptionLabel(v))}</option>`;
    });
    if (valeur !== '' && !liste.includes(valeur)) {
        const horsListe = IMAGE_OPTION_TEXT.unsupported.replace('{option}', imageOptionLabel(valeur));
        html += `<option value="${esc(valeur)}">${esc(horsListe)}</option>`;
    }
    sel.innerHTML = html;
    sel.value = valeur;
}

// ---- Prompts système livrés par type d'outil (ToolPrompts = source unique) ----
// Le champ est PRÉ-REMPLI avec ce texte : l'administrateur voit ce que fait
// réellement l'outil, au lieu d'un champ vide et d'un « comportement par
// défaut » invisible. Vider le champ n'enregistre rien → le défaut reprend la
// main côté serveur, d'où l'équivalence des deux états.
const TOOL_PROMPT_DEFAULTS = <?= json_encode(ToolPrompts::all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/** Le texte affiché est-il un défaut livré (donc remplaçable sans rien perdre) ? */
function isDefaultToolPrompt(value) {
    const v = String(value || '').trim();
    if (v === '') return true;
    return Object.values(TOOL_PROMPT_DEFAULTS).some(d => d.trim() === v);
}

function isOpenAiNativeImageModel(model) {
    return !OPENAI_IMAGE_ENDPOINT_MODELS.has(String(model || ''));
}

function getLlmModels(provider) {
    const models = LLM_MODELS_CATALOG[provider] || {};
    return Object.entries(models).map(([id, info]) => ({ id, label: info.label || id }));
}

function populateModelSelect(sel, provider, currentModel) {
    sel.innerHTML = '';
    getLlmModels(provider).forEach(({ id, label }) => {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = label;
        if (id === currentModel) opt.selected = true;
        sel.appendChild(opt);
    });
    if (!sel.value && currentModel) {
        // Modèle non trouvé dans le catalogue : ajouter quand même
        const opt = document.createElement('option');
        opt.value = currentModel;
        opt.textContent = currentModel;
        opt.selected = true;
        sel.appendChild(opt);
    }
}

// =====================================================================
// FORMULAIRE CRÉATION
// =====================================================================
(function () {
    const typeRadios    = [...document.querySelectorAll('#cTypePicker input[name="type"]')];
    const currentType   = () => (typeRadios.find(r => r.checked) || {}).value || 'image';
    const cProvImg      = document.getElementById('cProviderImage');
    const cModelImgOai  = document.getElementById('cModelImgOai');
    const cModelHtml    = document.getElementById('cModelHtml');
    const cProvHtml     = document.getElementById('cProviderHtml');
    const cTempRange    = document.getElementById('cTemp');
    const cTempVal      = document.getElementById('cTempVal');

    function updateCreate() {
        const t             = currentType();
        const isIllustrated = t === 'html_illustrated';
        const isMap     = t === 'map'; // même moteur qu'un outil HTML : un LLM du catalogue
        const isPresentation = t === 'presentation'; // idem : LLM du catalogue + prompt/température
        const isN8n     = t === 'n8n'; // aucun LLM : une instance n8n (URL + clé + tag)
        const isGamma   = t === 'gamma'; // aucun LLM de MIA : l'API gamma.app (clé + options)
        const isHtml    = t === 'html' || isIllustrated || isMap || isPresentation;
        const isVideo   = t === 'video';
        const isImage   = !isHtml && !isVideo && !isN8n && !isGamma;
        const imgGemini = isImage && cProvImg.value === 'gemini';
        const imgOpenAiNative = isImage && !imgGemini && isOpenAiNativeImageModel(cModelImgOai.value);
        const imgImagen = imgGemini && document.getElementById('cModelImgGem').value.includes('imagen');

        // Visibilité sections
        show('cProviderImageWrap',  isImage);   disable('cProviderImage',  !isImage);
        show('cModelImgOaiWrap',    isImage && !imgGemini); disable('cModelImgOai', !isImage || imgGemini);
        show('cModelImgGemWrap',    imgGemini); disable('cModelImgGem', !imgGemini);
        show('cProviderHtmlWrap',   isHtml);    disable('cProviderHtml', !isHtml);
        show('cModelHtmlWrap',      isHtml);    disable('cModelHtml',    !isHtml);
        show('cProviderVideoWrap',  isVideo);   disable('cProviderVideo', !isVideo);
        show('cModelVideoWrap',     isVideo);   disable('cModelVideo',    !isVideo);
        show('cOptsImgOai',         isImage && !imgGemini && !imgOpenAiNative);
        show('cOptsImgOaiNative',   isImage && !imgGemini && imgOpenAiNative);
        show('cOptsImgImagen',      imgImagen);
        show('cOptsHtml',           isHtml);
        show('cOptsIllustrated',    isIllustrated);
        show('cOptsPresentation',   isPresentation);
        show('cOptsN8n',            isN8n);
        show('cOptsGamma',          isGamma);
        // Le laïus sur le « moteur » (fournisseur, modèle, clé dans Gestion
        // LLMs) est faux pour n8n et Gamma : aucun LLM de MIA n'intervient.
        show('cStep2Sub',           !isN8n && !isGamma);
        show('cStep2SubN8n',        isN8n);
        show('cStep2SubGamma',      isGamma);
        // Les clés API n8n et Gamma vivent DANS leur section (elles y sont
        // obligatoires, pas « optionnelles ») : le champ générique de l'étape 3
        // est masqué, et surtout désactivé — trois champs `api_key` postés sinon.
        show('cApiKeyWrap',         isImage || isVideo);
        disableByContainer('cOptsImgOai', !isImage || imgGemini || imgOpenAiNative);
        disableByContainer('cOptsImgOaiNative', !isImage || imgGemini || !imgOpenAiNative);
        disableByContainer('cOptsImgImagen', !imgImagen);
        disableByContainer('cOptsHtml', !isHtml);
        disableByContainer('cOptsIllustrated', !isIllustrated);
        // Indispensable : cOptsIllustrated et cOptsPresentation portent tous
        // deux un champ config_image_tool_id — sans désactivation du conteneur
        // caché, le POST en contiendrait deux.
        disableByContainer('cOptsPresentation', !isPresentation);
        disableByContainer('cOptsN8n', !isN8n);
        disableByContainer('cOptsGamma', !isGamma);
        disableByContainer('cApiKeyWrap', !(isImage || isVideo));

        if (isHtml) populateModelSelect(cModelHtml, cProvHtml.value, '');
        syncCreatePrompt(t);
    }

    // Le prompt suit le type choisi tant que l'administrateur n'a rien écrit
    // de personnel : un texte qu'il a saisi n'est JAMAIS écrasé par un
    // changement de type (il ne serait récupérable nulle part).
    function syncCreatePrompt(type) {
        const ta = document.getElementById('cCfgSystemPrompt');
        if (!ta) return;
        if (isDefaultToolPrompt(ta.value)) {
            ta.value = TOOL_PROMPT_DEFAULTS[type] || '';
        }
    }

    function show(id, visible) {
        const el = document.getElementById(id);
        if (el) el.style.display = visible ? '' : 'none';
    }
    function disable(id, disabled) {
        const el = document.getElementById(id);
        if (el) el.disabled = disabled;
    }
    function disableByContainer(id, disabled) {
        const el = document.getElementById(id);
        if (!el) return;
        el.querySelectorAll('input,select,textarea,button').forEach(ctrl => {
            ctrl.disabled = disabled;
        });
    }

    function syncTypeCards() {
        typeRadios.forEach(r => {
            const card = r.closest('.type-card');
            if (card) card.classList.toggle('is-selected', r.checked);
        });
    }
    typeRadios.forEach(r => r.addEventListener('change', () => { syncTypeCards(); updateCreate(); }));
    cProvImg.addEventListener('change', updateCreate);
    cModelImgOai.addEventListener('change', updateCreate);
    document.getElementById('cModelImgGem').addEventListener('change', updateCreate);
    cProvHtml.addEventListener('change', function () {
        populateModelSelect(cModelHtml, this.value, '');
    });

    cTempRange?.addEventListener('input', function () {
        if (cTempVal) cTempVal.textContent = this.value;
    });

    // Style « Personnalisé… » : affiche/masque le champ texte associé (create + edit).
    document.querySelectorAll('.cfg-style-select').forEach(sel => {
        const sync = () => {
            const custom = sel.parentElement.querySelector('.cfg-style-custom');
            if (custom) custom.style.display = (sel.value === '__custom__') ? '' : 'none';
        };
        sel.addEventListener('change', sync);
        sync();
    });

    syncTypeCards();
    updateCreate();
})();

// =====================================================================
// ACCORDÉON DU FORMULAIRE CRÉATION
// Fermé par défaut (seul le bouton « Ajouter un outil » est visible),
// puis les 3 étapes s'ouvrent séquentiellement. Une étape déjà visitée
// reste rouvrable via son en-tête ; une étape non atteinte est verrouillée.
// =====================================================================
(function () {
    const openBtn  = document.getElementById('addToolOpen');
    const openers  = [...document.querySelectorAll('.js-add-tool-open')];
    const card     = document.getElementById('addToolCard');
    const closeBtn = document.getElementById('addToolClose');
    const form     = document.getElementById('createForm');
    if (!openers.length || !card || !form) return;

    const steps    = [...form.querySelectorAll('.tool-step')];
    const unlocked = new Set([1]);
    let maxReached = 1;

    function openStep(n) {
        steps.forEach(s => {
            const num    = +s.dataset.step;
            const isOpen = num === n;
            s.classList.toggle('is-open',   isOpen);
            s.classList.toggle('is-locked', !unlocked.has(num));
            s.classList.toggle('is-done',   num < maxReached && !isOpen);
            s.querySelector('.step-head')?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    function goTo(n) {
        for (let i = 1; i <= n; i++) unlocked.add(i);
        if (n > maxReached) maxReached = n;
        updateSummaries();
        openStep(n);
    }

    function selText(id) {
        const el = document.getElementById(id);
        if (!el || el.disabled) return '';
        const o = el.selectedOptions ? el.selectedOptions[0] : null;
        return o ? o.textContent.trim() : '';
    }

    function updateSummaries() {
        const checked = form.querySelector('#cTypePicker input:checked');
        const typeCard = checked ? checked.closest('.type-card') : null;
        const s1 = document.getElementById('cStep1Summary');
        if (s1) s1.textContent = typeCard ? (typeCard.querySelector('.tc-title')?.textContent || '') : '';

        const t = checked ? checked.value : 'image';
        let prov = '', mod = '';
        if (t === 'n8n') {
            const url = form.querySelector('[name="config_n8n_base_url"]');
            const tag = form.querySelector('[name="config_n8n_tag"]');
            const s2n8n = document.getElementById('cStep2Summary');
            if (s2n8n) s2n8n.textContent = [url?.value, tag?.value ? 'tag ' + tag.value : ''].filter(Boolean).join(' · ');
            return;
        }
        if (t === 'gamma') {
            const theme  = form.querySelector('[name="config_gamma_theme_id"]');
            const cards  = form.querySelector('[name="config_gamma_max_cards"]');
            const s2g    = document.getElementById('cStep2Summary');
            if (s2g) s2g.textContent = ['gamma.app', theme?.value ? txt('theme', { id: theme.value }) : '',
                cards?.value ? cards.value + ' slides max' : ''].filter(Boolean).join(' · ');
            return;
        }
        if (t === 'video') {
            prov = selText('cProviderVideo'); mod = selText('cModelVideo');
        } else if (t === 'image') {
            prov = selText('cProviderImage'); mod = selText('cModelImgOai') || selText('cModelImgGem');
        } else {
            prov = selText('cProviderHtml'); mod = selText('cModelHtml');
        }
        const s2 = document.getElementById('cStep2Summary');
        if (s2) s2.textContent = [prov, mod].filter(Boolean).join(' · ');
    }

    // Deux points d'entrée (bouton du header + carte fantôme de la grille) :
    // le bouton du header s'efface pendant que le formulaire est ouvert, la
    // carte fantôme reste (la masquer ferait sauter la grille) et re-scrolle.
    openers.forEach(btn => btn.addEventListener('click', () => {
        if (openBtn) openBtn.hidden = true;
        card.hidden = false;
        openStep(1);
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }));
    closeBtn?.addEventListener('click', () => {
        card.hidden = true;
        if (openBtn) openBtn.hidden = false;
    });

    // En-têtes cliquables (étapes déjà atteintes uniquement).
    steps.forEach(s => {
        s.querySelector('.step-head')?.addEventListener('click', () => {
            const num = +s.dataset.step;
            if (!unlocked.has(num)) return;
            updateSummaries();
            openStep(num);
        });
    });

    // Étape 1 : choisir un type ouvre l'étape 2 (léger délai pour laisser le radio se poser).
    form.querySelectorAll('#cTypePicker .type-card').forEach(c => {
        c.addEventListener('click', () => {
            // Type indisponible (aucun modèle en service) : le radio reste
            // sur le type précédent, ouvrir l'étape 2 montrerait ses réglages.
            if (c.classList.contains('is-disabled')) return;
            setTimeout(() => goTo(2), 150);
        });
    });

    // Boutons « Continuer ».
    form.querySelectorAll('[data-step-next]').forEach(btn => {
        btn.addEventListener('click', () => goTo(+btn.dataset.stepNext));
    });

    // Entrée dans un champ des étapes 1-2 = avancer, pas soumettre (le champ
    // « Nom » requis de l'étape 3 serait masqué → validation navigateur muette).
    form.addEventListener('keydown', e => {
        if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;
        const step = e.target.closest('.tool-step');
        const num  = step ? +step.dataset.step : 3;
        if (num < 3) {
            e.preventDefault();
            goTo(num + 1);
        }
    });

    // Filet : si la validation échoue sur un champ d'une étape repliée, on l'ouvre.
    form.addEventListener('invalid', e => {
        const step = e.target.closest('.tool-step');
        if (step) goTo(+step.dataset.step);
    }, true);

    form.addEventListener('change', updateSummaries);
})();

// =====================================================================
// MODAL ÉDITION
// =====================================================================
function openEditModal(tool) {
    const form = document.getElementById('editForm');
    form.action = '/admin/tools/' + tool.id;

    document.getElementById('eName').value      = tool.name;
    document.getElementById('eSortOrder').value = tool.sort_order;
    document.getElementById('eActive').checked  = !!+tool.is_active;
    document.getElementById('eTypeHidden').value    = tool.type;
    const isIllustrated = tool.type === 'html_illustrated';
    const isMap   = tool.type === 'map'; // même moteur qu'un outil HTML (LLM du catalogue)
    const isPresentation = tool.type === 'presentation';
    const isVideo = tool.type === 'video';
    const isN8n   = tool.type === 'n8n';
    const isGamma = tool.type === 'gamma';
    const isHtml  = tool.type === 'html' || isIllustrated || isMap || isPresentation;
    const eTypeBadge = document.getElementById('eTypeDisplay');
    eTypeBadge.textContent = isIllustrated
        ? txt('typeIllustrated')
        : (isMap ? txt('typeMap')
        : (isPresentation ? txt('typePresentation')
        : (isN8n ? txt('typeN8n')
        : (isGamma ? txt('typeGamma')
        : (tool.type === 'html' ? 'HTML' : (isVideo ? txt('typeVideo') : txt('typeImage')))))));
    eTypeBadge.className = 'badge ' + (isVideo ? 'badge-red' : ((isN8n || isGamma) ? 'badge-gray' : (isHtml ? 'badge-org' : 'badge-green')));

    const cfg = tool.config_json ? JSON.parse(tool.config_json) : {};

    document.getElementById('eImageSection').style.display = (!isHtml && !isVideo && !isN8n && !isGamma) ? '' : 'none';
    document.getElementById('eHtmlSection').style.display  = isHtml ? '' : 'none';
    document.getElementById('eIllustratedSection').style.display = isIllustrated ? '' : 'none';
    document.getElementById('ePresentationSection').style.display = isPresentation ? '' : 'none';
    document.getElementById('eVideoSection').style.display = isVideo ? '' : 'none';
    document.getElementById('eN8nSection').style.display   = isN8n ? '' : 'none';
    document.getElementById('eGammaSection').style.display = isGamma ? '' : 'none';

    setSectionDisabled('eImageSection', isHtml || isVideo || isN8n || isGamma);
    setSectionDisabled('eHtmlSection', !isHtml);
    setSectionDisabled('eIllustratedSection', !isIllustrated);
    // Les deux sections portent un champ config_image_tool_id : désactiver la
    // section cachée évite de poster deux valeurs pour le même nom.
    setSectionDisabled('ePresentationSection', !isPresentation);
    setSectionDisabled('eVideoSection', !isVideo);
    // Quatre sections portent un champ `api_key` (image, vidéo, n8n, Gamma) :
    // celles qui sont cachées doivent être désactivées, sinon le POST en
    // contient plusieurs.
    setSectionDisabled('eN8nSection', !isN8n);
    setSectionDisabled('eGammaSection', !isGamma);

    if (isN8n) {
        document.getElementById('eN8nBaseUrl').value     = cfg.base_url || '';
        document.getElementById('eN8nTag').value         = cfg.tag || '<?= htmlspecialchars(N8nService::DEFAULT_TAG) ?>';
        document.getElementById('eN8nHeaderName').value  = cfg.webhook_header_name || '';
        document.getElementById('eN8nHeaderValue').value = '';
        document.getElementById('eN8nHeaderClear').checked = false;
        document.getElementById('eN8nAutoRead').checked  = !!cfg.auto_read;
        document.getElementById('eN8nReadTag').textContent =
            (cfg.tag || '<?= htmlspecialchars(N8nService::DEFAULT_TAG) ?>') + '<?= htmlspecialchars(N8nService::READ_SUFFIX) ?>';
    }

    if (isGamma) {
        document.getElementById('eGammaApiKey').value       = '';
        document.getElementById('eGammaThemeId').value      = cfg.theme_id || '';
        document.getElementById('eGammaMaxCards').value     = cfg.max_cards || <?= GammaService::DEFAULT_MAX_CARDS ?>;
        document.getElementById('eGammaImageSource').value  = cfg.image_source || 'aiGenerated';
        document.getElementById('eGammaExportAs').value     = cfg.export_as || '';
        document.getElementById('eGammaInstructions').value = cfg.default_instructions || '';
    }

    if (isPresentation) {
        document.getElementById('ePresImageToolId').value    = cfg.image_tool_id ? String(cfg.image_tool_id) : '';
        document.getElementById('ePresDefaultSlides').value  = cfg.default_slides || <?= DeckSpec::DEFAULT_SLIDES ?>;
    }

    if (isIllustrated) {
        document.getElementById('eImageToolId').value = cfg.image_tool_id ? String(cfg.image_tool_id) : '';
        document.getElementById('eMaxImages').value   = cfg.max_images || 3;
        // Style : preset si la directive correspond à une option, sinon « Personnalisé… ».
        const styleSel = document.getElementById('eImageStyle');
        const styleCustom = document.getElementById('eImageStyleCustom');
        const sv = cfg.image_style || '';
        if (styleSel) {
            const isPreset = sv && [...styleSel.options].some(o => o.value === sv);
            if (!sv) { styleSel.value = ''; if (styleCustom) styleCustom.value = ''; }
            else if (isPreset) { styleSel.value = sv; }
            else { styleSel.value = '__custom__'; if (styleCustom) styleCustom.value = sv; }
            styleSel.dispatchEvent(new Event('change')); // synchronise la visibilité du champ custom
        }
    }

    if (isHtml) {
        const pSel = document.getElementById('eProviderHtml');
        const mSel = document.getElementById('eModelHtml');
        pSel.value = tool.provider;
        populateModelSelect(mSel, tool.provider, tool.model);

        // Prompt enregistré, sinon le défaut livré pour ce type : le champ
        // montre toujours le texte réellement envoyé au modèle.
        document.getElementById('eCfgSystemPrompt').value =
            (cfg.system_prompt || '').trim() || (TOOL_PROMPT_DEFAULTS[tool.type] || '');
        const temp = cfg.temperature ?? 0.7;
        document.getElementById('eTemp').value  = temp;
        document.getElementById('eTempVal').textContent = temp;

        pSel.onchange = function () { populateModelSelect(mSel, this.value, ''); };
        document.getElementById('eTemp').oninput = function () {
            document.getElementById('eTempVal').textContent = this.value;
        };
    } else if (isVideo) {
        const pvSel = document.getElementById('eProviderVideo');
        const mSel  = document.getElementById('eModelVideo');

        pvSel.value = tool.provider || 'openai';
        showCurrentModel(mSel, tool.model || '');
        document.getElementById('eVideoApiKey').value = '';
    } else {
        const pSel = document.getElementById('eProviderImage');
        const oaiModelSel = document.getElementById('eModelImgOai');
        const gemModelSel = document.getElementById('eModelImgGem');
        pSel.value = tool.provider;
        // Taille et qualité enregistrées passées ICI : les listes dépendent du
        // modèle, les poser après coup les écraserait au premier remplissage.
        updateEditImageProvider(tool, cfg);
        pSel.onchange = function () { updateEditImageProvider(null); };
        oaiModelSel.onchange = function () { updateEditImageOptions(); };
        gemModelSel.onchange = function () { updateEditImageOptions(); };
        if (cfg.aspect_ratio) document.getElementById('eCfgAspect').value  = cfg.aspect_ratio;
        document.getElementById('eApiKey').value = '';
    }

    document.getElementById('editModal').style.display = 'flex';
    // Tous les champs sont posés : l'enregistrement automatique peut prendre
    // la main. L'armer plus tôt ferait passer le remplissage de la modale pour
    // une modification de l'utilisateur.
    form.dataset.autosave = 'on';
}

function setSectionDisabled(id, disabled) {
    const root = document.getElementById(id);
    if (!root) return;
    root.querySelectorAll('input,select,textarea,button').forEach(el => {
        el.disabled = disabled;
    });
}

// Modèles arrêtés par leur fournisseur (option `data-retired`) : montrés
// SEULEMENT quand c'est le modèle actuel de l'outil ouvert, jamais proposés à
// la place d'un modèle en service. Un modèle inconnu de la liste y est ajouté
// tel quel. Sans l'un ni l'autre, l'enregistrement automatique de la fiche
// remplacerait en silence le modèle de l'outil par le premier de la liste.
function showCurrentModel(sel, current) {
    if (!sel) return;
    if (current && ![...sel.options].some(o => o.value === current)) {
        const opt = document.createElement('option');
        opt.value = current;
        opt.textContent = current;
        sel.appendChild(opt);
    }
    [...sel.options].forEach(o => {
        const masque = o.dataset.retired === '1' && o.value !== current;
        o.hidden = masque;
        o.disabled = masque;
    });
    if (current) sel.value = current;
}

function updateEditImageProvider(tool, cfg) {
    const pSel    = document.getElementById('eProviderImage');
    const isGem   = pSel.value === 'gemini';
    const oaiWrap = document.getElementById('eModelImgOaiWrap');
    const gemWrap = document.getElementById('eModelImgGemWrap');
    const oaiSel  = document.getElementById('eModelImgOai');
    const gemSel  = document.getElementById('eModelImgGem');

    oaiWrap.style.display = isGem ? 'none' : '';
    gemWrap.style.display = isGem ? ''     : 'none';
    oaiSel.disabled = isGem;
    gemSel.disabled = !isGem;

    if (tool) {
        showCurrentModel(oaiSel, isGem ? '' : tool.model);
        showCurrentModel(gemSel, isGem ? tool.model : '');
    } else {
        // Changement de fournisseur : jamais un modèle arrêté (masqué) en
        // sélection — le premier modèle en service de la liste.
        const sel = isGem ? gemSel : oaiSel;
        const choisi = sel.options[sel.selectedIndex];
        if (!choisi || choisi.disabled) {
            const premier = [...sel.options].find(o => !o.disabled);
            if (premier) sel.value = premier.value;
        }
    }

    updateEditImageOptions(cfg);
}

function updateEditImageOptions(cfg) {
    const isGem = document.getElementById('eProviderImage').value === 'gemini';
    const gemModel = document.getElementById('eModelImgGem').value;
    const oaiModel = document.getElementById('eModelImgOai').value;
    const isImagen = isGem && gemModel.includes('imagen');
    const isOpenAiNative = !isGem && isOpenAiNativeImageModel(oaiModel);

    // Changer de modèle rejoue les listes ; sans `cfg` (ouverture de la fiche),
    // on repart de ce qui est déjà choisi à l'écran.
    const sizeSel = document.getElementById('eCfgSize');
    const qualSel = document.getElementById('eCfgQuality');
    const opts = OPENAI_IMAGE_MODEL_OPTIONS[oaiModel] || { sizes: [], qualities: [] };
    fillImageOptionSelect(sizeSel, opts.sizes, cfg ? (cfg.size || '') : sizeSel.value);
    fillImageOptionSelect(qualSel, opts.qualities, cfg ? (cfg.quality || '') : qualSel.value);

    document.getElementById('eOptsImgOai').style.display       = (!isGem && !isOpenAiNative) ? '' : 'none';
    document.getElementById('eOptsImgOaiNative').style.display = (!isGem && isOpenAiNative)  ? '' : 'none';
    document.getElementById('eOptsImgImagen').style.display    = isImagen ? '' : 'none';

    setSectionDisabled('eOptsImgOai', isGem || isOpenAiNative);
    setSectionDisabled('eOptsImgOaiNative', isGem || !isOpenAiNative);
    setSectionDisabled('eOptsImgImagen', !isImagen);
}

function closeEditModal() {
    const form = document.getElementById('editForm');
    const hide = () => {
        const m = document.getElementById('editModal');
        if (m) m.style.display = 'none';
    };
    // La carte de l'outil (nom, type, état, dernier test) est rendue côté
    // serveur : sans rechargement, elle afficherait encore les anciennes
    // valeurs juste après une modification.
    if (form && window.MiaAutosave) {
        window.MiaAutosave.closeAndReload(form, hide);
    } else {
        hide();
    }
}
document.getElementById('cancelEdit')?.addEventListener('click', closeEditModal);
document.getElementById('cancelEditX')?.addEventListener('click', closeEditModal);
document.getElementById('editModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeEditModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const m = document.getElementById('editModal');
        if (m && m.style.display !== 'none') closeEditModal();
    }
});
// Toggle œil sur les champs « clé API dédiée » de la modale.
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.password-toggle');
    if (!btn) return;
    const input = btn.closest('.password-field')?.querySelector('input');
    if (!input) return;
    const hidden = input.type === 'password';
    input.type = hidden ? 'text' : 'password';
    const open = btn.querySelector('.eye-open');
    const closed = btn.querySelector('.eye-closed');
    if (open)   open.style.display   = hidden ? 'none' : '';
    if (closed) closed.style.display = hidden ? '' : 'none';
    btn.setAttribute('aria-label', hidden ? txt('hide') : txt('show'));
});

// =====================================================================
// TEST DES OUTILS CONFIGURES
// =====================================================================
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toolButtons = [...document.querySelectorAll('.js-test-tool')];
    const runAllBtn = document.getElementById('testAllToolsBtn');
    const globalResult = document.getElementById('toolsTestGlobal');
    const toolButtonsById = new Map(toolButtons.map(btn => [String(btn.dataset.toolId), btn]));
    let activeRunId = null;
    let pollTimer = null;

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    // Tester = droit « Outils — Modifier » (Personnes → Rôles) : sans lui,
    // les boutons restent grisés quoi qu'il arrive.
    const TOOLS_CAN_TEST = <?= json_encode($toolsCan['edit']) ?>;
    function setControlsRunning(isRunning) {
        toolButtons.forEach(btn => { btn.disabled = isRunning || !TOOLS_CAN_TEST; });
        if (runAllBtn) {
            runAllBtn.disabled = isRunning || toolButtons.length === 0 || !TOOLS_CAN_TEST;
            runAllBtn.textContent = isRunning ? txt('testsRunning') : txt('testAll');
        }
    }

    function renderItemStatus(item) {
        const toolId = String(item.tool_id || '');
        if (!toolId) return;

        const resultEl = document.getElementById('tool-test-result-' + toolId);
        if (!resultEl) return;

        const duration = Number.isFinite(Number(item.duration_ms))
            ? ` (${item.duration_ms} ms)`
            : '';

        if (item.status === 'pending') {
            resultEl.style.color = 'var(--text-muted)';
            resultEl.textContent = txt('testPending');
            return;
        }
        if (item.status === 'running') {
            resultEl.style.color = 'var(--accent)';
            resultEl.textContent = txt('testRunning');
            return;
        }
        if (item.status === 'ok') {
            resultEl.style.color = '#10a37f';
            resultEl.textContent = txt('testOk', { duree: duration, message: item.message || txt('testOkDefault') });
            return;
        }
        if (item.status === 'failed') {
            resultEl.style.color = '#ef4444';
            resultEl.textContent = txt('testFailed', { duree: duration, message: item.message || txt('testFailedDefault') });
            return;
        }

        resultEl.style.color = 'var(--text-muted)';
        resultEl.textContent = item.message || '';
    }

    function renderRun(run, items) {
        if (!run) {
            setControlsRunning(false);
            return;
        }

        const isRunning = run.status === 'queued' || run.status === 'running';
        setControlsRunning(isRunning);

        if (globalResult) {
            if (isRunning) {
                globalResult.style.color = 'var(--accent)';
                globalResult.textContent = txt('runProgress', { fait: run.done_count, total: run.total_count });
            } else {
                const total = Number(run.total_count || 0);
                const ok = Number(run.ok_count || 0);
                const fail = Number(run.fail_count || 0);
                globalResult.style.color = fail === 0 ? '#10a37f' : '#f59e0b';
                globalResult.textContent = total > 0
                    ? (fail > 0 ? txt('runSummaryFailed', { ok, total, n: fail }) : txt('runSummary', { ok, total }))
                    : '';
            }
        }

        (items || []).forEach(renderItemStatus);
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, options);
        const raw = await response.text();
        let data = null;
        try { data = JSON.parse(raw); } catch (_) {}
        if (!data) {
            throw new Error(txt('badResponse'));
        }
        if (!response.ok || data.ok === false) {
            throw new Error(data.message || txt('serverError', { statut: response.status }));
        }
        return data;
    }

    async function loadRun(runId) {
        const data = await fetchJson(`/admin/tools/tests/${runId}`);
        activeRunId = Number(data.run?.id || runId);
        renderRun(data.run, data.items);

        const isRunning = data.run && (data.run.status === 'queued' || data.run.status === 'running');
        if (!isRunning) {
            stopPolling();
        }
    }

    function startPolling() {
        stopPolling();
        if (!activeRunId) return;

        pollTimer = setInterval(async () => {
            try {
                await loadRun(activeRunId);
            } catch (_) {
                // silencieux, un prochain poll retentera
            }
        }, 2000);
    }

    async function startRun(url) {
        try {
            const data = await fetchJson(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken,
                },
                body: new URLSearchParams({}),
            });

            activeRunId = Number(data.run_id || 0);
            if (activeRunId > 0) {
                await loadRun(activeRunId);
                startPolling();
            }
        } catch (e) {
            if (globalResult) {
                globalResult.style.color = '#ef4444';
                globalResult.textContent = e.message || txt('cannotStart');
            }
        }
    }

    toolButtons.forEach(btn => {
        btn.addEventListener('click', async function () {
            const toolId = this.dataset.toolId;
            if (!toolId) return;
            if (toolButtonsById.has(toolId)) {
                const resultEl = document.getElementById('tool-test-result-' + toolId);
                if (resultEl) {
                    resultEl.style.color = 'var(--accent)';
                    resultEl.textContent = txt('testScheduled');
                }
            }
            await startRun(`/admin/tools/${toolId}/test`);
        });
    });

    runAllBtn?.addEventListener('click', async function () {
        await startRun('/admin/tools/tests/start');
    });

    (async function bootstrapRunState() {
        try {
            const data = await fetchJson('/admin/tools/tests/latest');
            if (!data.run) {
                return;
            }
            activeRunId = Number(data.run.id || 0);
            renderRun(data.run, data.items);
            if (data.run.status === 'queued' || data.run.status === 'running') {
                startPolling();
            }
        } catch (_) {
            // silencieux
        }
    })();
})();

// =====================================================================
// EXPRESSIONS DÉCLENCHEUSES DES CARTES
// Même mécanique que /admin/connectors : plus de bouton « Enregistrer », la
// zone part après une pause de frappe, re-rendu depuis la liste canonique du
// serveur. La liste est portée par la RÈGLE (le type), pas par l'outil :
// toutes les cartes de la même règle sont resynchronisées après un
// enregistrement.
// =====================================================================
(function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const pill  = window.MiaAutosave;

    function syncRuleCards(rule, data) {
        document.querySelectorAll(`.tool-card[data-trigger-rule="${rule}"]`).forEach(card => {
            const ta    = card.querySelector('.tool-triggers-input');
            const count = card.querySelector('.connector-triggers-count');
            const badge = card.querySelector('.connector-triggers-badge');
            // Jamais réécrire la zone en cours de frappe : le curseur sauterait.
            if (ta && document.activeElement !== ta) { ta.value = data.triggers.join('\n'); }
            if (count) count.textContent = String(data.triggers.length);
            if (badge) badge.hidden = !data.custom;
        });
    }

    document.querySelectorAll('.tool-card[data-trigger-rule]').forEach(card => {
        const rule   = card.dataset.triggerRule;
        const ta     = card.querySelector('.tool-triggers-input');
        const reset  = card.querySelector('.tool-triggers-reset');
        const status = card.querySelector('.connector-triggers-status');
        if (!ta || !reset) return;

        let timer = null;

        function send(fields, okLabel) {
            clearTimeout(timer);
            timer = null;
            reset.disabled = true;
            status.textContent = txt('saving');
            status.className = 'connector-triggers-status';
            if (pill) pill.paint('saving', txt('saving'));
            const body = new FormData();
            Object.keys(fields).forEach(k => body.append(k, fields[k]));
            fetch('/admin/tools/triggers', {
                method: 'POST',
                headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
                body,
            })
            .then(r => r.json().catch(() => ({ ok: false })))
            .then(data => {
                if (!data || !data.ok || !Array.isArray(data.triggers)) throw new Error();
                syncRuleCards(rule, data);
                status.textContent = okLabel;
                status.classList.add('is-ok');
                if (pill) pill.paint('saved', okLabel);
                setTimeout(() => { status.textContent = ''; }, 2500);
            })
            .catch(() => {
                status.textContent = txt('triggersFailed');
                status.classList.add('is-err');
                if (pill) pill.paint('error', txt('triggersFailedPill'));
            })
            .finally(() => { reset.disabled = false; });
        }

        ta.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => send({ rule, triggers: ta.value }, txt('savedCheck')), 900);
        });
        // Quitter la zone ne doit pas laisser la dernière frappe en suspens.
        ta.addEventListener('blur', () => {
            if (timer) send({ rule, triggers: ta.value }, txt('savedCheck'));
        });
        reset.addEventListener('click', () => send({ rule, triggers_reset: '1' }, txt('defaultsRestored')));
    });
})();

// =====================================================================
// INTERRUPTEUR ACTIF/INACTIF DES CARTES
// Même mécanique que /admin/connectors : enregistrement immédiat, et
// l'état affiché ne bascule qu'après le OK serveur — un échec réseau
// doit se voir, pas mentir. Couvre aussi les cartes intégrées « Dictée
// vocale » et « Conversation vocale » (POST /admin/tools/builtin, la clé
// Settings de la carte lue dans data-builtin-key — une carte à la fois,
// le handler ne touche qu'aux clés présentes dans le POST).
// =====================================================================
(function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // État explicite en deux lignes : « Actif / N groupes ». La portée vient
    // du serveur (data-on) : un outil actif n'est proposé qu'aux groupes qui
    // l'autorisent — seule la dictée, réglage global, vaut pour tous.
    function paintState(card, on) {
        // Un outil hors service (modèle arrêté) reste atténué, même actif.
        card.classList.toggle('is-off', !on || card.dataset.retired === '1');
        const label = card.querySelector('.tool-state-label');
        const sub   = card.querySelector('.tool-state-sub');
        if (label) {
            label.textContent = on ? txt('active') : txt('inactive');
            label.classList.add('is-flash');
            setTimeout(() => label.classList.remove('is-flash'), 900);
        }
        if (sub) sub.textContent = on ? (sub.dataset.on || txt('usableByAll')) : txt('hiddenFromChat');
        const noGroup = card.querySelector('[id^="tool-no-group-"]');
        if (noGroup) noGroup.hidden = !on;
    }

    document.querySelectorAll('.tool-card').forEach(card => {
        const input = card.querySelector('.tool-active-toggle');
        if (!input) return;
        const builtinKey = card.dataset.builtinKey || ''; // dictée vocale, conversation vocale
        const isBuiltin  = !card.dataset.toolId && builtinKey !== '';
        input.addEventListener('change', () => {
            const wanted = input.checked;
            const pill   = window.MiaAutosave;
            input.disabled = true;
            card.classList.add('is-saving');
            if (pill) pill.paint('saving', txt('saving'));
            const body = new FormData();
            body.append(isBuiltin ? builtinKey : 'enabled', wanted ? '1' : '0');
            const url = isBuiltin
                ? '/admin/tools/builtin'
                : `/admin/tools/${card.dataset.toolId}/toggle`;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
                body,
            })
            .then(r => r.json().catch(() => ({ ok: false })))
            .then(data => {
                if (!data || !data.ok) throw new Error();
                paintState(card, wanted);
                if (pill) pill.paint('saved', txt('saved'));
            })
            .catch(() => {
                input.checked = !wanted;        // rien enregistré : retour à l'état réel
                const label = card.querySelector('.tool-state-label');
                if (label) label.textContent = txt('failed');
                if (pill) pill.paint('error', txt('toolNotChanged'));
                setTimeout(() => paintState(card, input.checked), 2000);
            })
            .finally(() => {
                input.disabled = false;
                card.classList.remove('is-saving');
            });
        });
    });

    // Choix d'une carte intégrée (plafond des boucles de workflow) : même
    // route, la valeur choisie ; retour à l'ancienne valeur si le serveur refuse.
    document.querySelectorAll('.tool-builtin-option-choice').forEach(select => {
        const key   = select.dataset.builtinKey || '';
        const state = select.closest('.tool-builtin-option')?.querySelector('.tool-builtin-option-state');
        if (!key) return;
        let current = select.value;
        select.addEventListener('change', () => {
            const wanted = select.value;
            const pill   = window.MiaAutosave;
            select.disabled = true;
            if (pill) pill.paint('saving', txt('saving'));
            const body = new FormData();
            body.append(key, wanted);
            fetch('/admin/tools/builtin', {
                method: 'POST',
                headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
                body,
            })
            .then(r => r.json().catch(() => ({ ok: false })))
            .then(data => {
                if (!data || !data.ok) throw new Error();
                current = wanted;
                if (state) state.textContent = '';
                if (pill) pill.paint('saved', txt('saved'));
            })
            .catch(() => {
                select.value = current;
                if (state) state.textContent = txt('retry');
                if (pill) pill.paint('error', txt('settingNotChanged'));
            })
            .finally(() => { select.disabled = false; });
        });
    });

    // Options d'une carte intégrée (« Proposer “Agir sans validation” ») :
    // une case = SA clé (data-builtin-key sur la case), même route, même
    // règle — la case ne bascule qu'après le OK du serveur.
    document.querySelectorAll('.tool-builtin-option-toggle').forEach(input => {
        const key   = input.dataset.builtinKey || '';
        const state = input.closest('.tool-builtin-option')?.querySelector('.tool-builtin-option-state');
        if (!key) return;
        input.addEventListener('change', () => {
            const wanted = input.checked;
            const pill   = window.MiaAutosave;
            input.disabled = true;
            if (pill) pill.paint('saving', txt('saving'));
            const body = new FormData();
            body.append(key, wanted ? '1' : '0');
            fetch('/admin/tools/builtin', {
                method: 'POST',
                headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
                body,
            })
            .then(r => r.json().catch(() => ({ ok: false })))
            .then(data => {
                if (!data || !data.ok) throw new Error();
                if (state) state.textContent = '';
                if (pill) pill.paint('saved', txt('saved'));
            })
            .catch(() => {
                input.checked = !wanted;        // rien enregistré : retour à l'état réel
                if (state) state.textContent = txt('retry');
                if (pill) pill.paint('error', txt('optionNotChanged'));
            })
            .finally(() => { input.disabled = false; });
        });
    });
})();
</script>
</body>
</html>
