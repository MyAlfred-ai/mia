<?php
// Assistants autorisables via les groupes : organisation + actifs uniquement
// (cf. UserGroup::setProfileAccess qui rejette le reste à la source).
$eligibleProfiles = array_values(array_filter(
    $profiles,
    static fn(array $p): bool => empty($p['is_personal']) && !empty($p['is_active'])
));

// Nom affiché d'un groupe : le groupe par défaut porte le nom LIVRÉ
// « Par défaut », qui suit la langue de l'écran tant que personne ne
// l'a renommé (I18n::editorial) ; un nom écrit par un administrateur
// reste tel quel. Affichage seulement : jamais dans un champ.
$groupLabel = static fn(array $g): string => !empty($g['is_default'])
    ? I18n::editorial((string)$g['name'], 'Par défaut')
    : (string)$g['name'];

// Charge utile JS : une entrée par groupe, consommée par la modale d'édition.
// `name` = valeur du champ (jamais traduite), `label` = nom affiché.
$groupsPayload = [];
foreach ($groups as $g) {
    $gid = (int)$g['id'];
    $members = $usersByGroup[$gid] ?? [];
    $groupsPayload[$gid] = [
        'id'          => $gid,
        'name'        => (string)$g['name'],
        'label'       => $groupLabel($g),
        'quota'       => isset($g['credit_quota_k']) && $g['credit_quota_k'] !== null
            ? (int)$g['credit_quota_k']
            : (isset($g['token_quota']) && $g['token_quota'] !== null ? (int)$g['token_quota'] : null),
        'is_active'   => !empty($g['is_active']),
        'is_default'  => !empty($g['is_default']),
        'member_ids'  => array_map(static fn(array $u): int => (int)$u['id'], $members),
        'profile_ids' => array_map('intval', $g['profile_ids'] ?? []),
        'tool_ids'    => array_map('intval', $g['tool_ids'] ?? []),
        'llm_keys'    => array_map('strval', $g['llm_keys'] ?? []),
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>"><meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>"><title><?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?> — <?= te('Groupes') ?></title>
    <?php $mainCssVersion = @filemtime(ROOT . '/public/assets/css/main.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)$mainCssVersion ?>">
    <?php require ROOT . "/templates/partials/theme-vars.php"; ?></head>
<body class="admin-page">
<?php $page = 'user-groups'; require ROOT . '/templates/partials/admin-nav.php'; ?>
<div class="admin-content">
    <div class="ug-header">
        <div>
            <h1><?= te('Groupes') ?></h1>
            <p class="ug-header-sub"><?= te("Membres, droits d'accès (assistants, outils, LLMs) et quotas par groupe.") ?></p>
        </div>
        <?php if (Session::can('groups.create')): ?>
        <button type="button" class="btn-primary ug-create-btn" id="ugCreateBtn">+ <?= te('Nouveau groupe') ?></button>
        <?php endif; ?>
    </div>

    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"   style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:20px">
        <details class="admin-collapse">
            <summary class="admin-collapse-summary">
                <span class="admin-collapse-caret">▸</span>
                <span><?= te('Comment fonctionnent les groupes') ?></span>
            </summary>
            <div class="admin-collapse-content" style="font-size:12px;color:var(--text-muted);display:flex;flex-direction:column;gap:6px">
                <div><?= strtr(teb("{b}Groupe « Par défaut » :{/b} il contient automatiquement {i}tous{/i} les utilisateurs (y compris chaque nouveau compte) et ne peut pas être supprimé. Ses droits constituent le socle commun de la plateforme."), ['{i}' => '<em>', '{/i}' => '</em>']) ?></div>
                <div><?= strtr(teb("{b}Les groupes s'additionnent :{/b} les droits (LLMs, assistants, outils) de chaque groupe actif s'ajoutent. Un utilisateur dans plusieurs groupes voit l'{i}union{/i} de tout ce que ces groupes autorisent."), ['{i}' => '<em>', '{/i}' => '</em>']) ?></div>
                <div><?= strtr(teb("{b}Quota mensuel :{/b} on retient le quota {i}le plus généreux{/i} parmi ses groupes actifs qui en définissent un. Un groupe sans quota est {i}neutre{/i} : il n'ouvre rien. Un quota défini sur l'utilisateur lui-même prend toujours le dessus."), ['{i}' => '<em>', '{/i}' => '</em>']) ?></div>
                <div><?= teb('{b}Groupes inactifs :{/b} ignorés (ni droits, ni quota).') ?></div>
                <div><?= strtr(teb("{b}Liste LLM vide :{/b} un groupe sans aucun LLM coché est neutre. Un utilisateur dont seul le groupe par défaut est actif — et sans liste — n'a accès qu'au {i}LLM par défaut{/i} de l'application."), ['{i}' => '<em>', '{/i}' => '</em>']) ?></div>
            </div>
        </details>
    </div>

    <div class="ug-grid">
        <?php foreach ($groups as $group):
            $gid       = (int)$group['id'];
            $isDefault = !empty($group['is_default']);
            $members   = $usersByGroup[$gid] ?? [];
            $payload   = $groupsPayload[$gid];
            $quota     = $payload['quota'];
            $llmCount  = count($payload['llm_keys']);
        ?>
        <article class="ug-card <?= $isDefault ? 'ug-card-default' : '' ?>" data-group-id="<?= $gid ?>" tabindex="0" role="button" aria-label="<?= te('Gérer le groupe {nom}', ['nom' => $groupLabel($group)]) ?>">
            <div class="ug-card-head">
                <div class="ug-card-name">
                    <?php if ($isDefault): ?><span class="ug-star" aria-hidden="true">★</span><?php endif; ?>
                    <?= htmlspecialchars($groupLabel($group)) ?>
                </div>
                <div class="ug-card-badges">
                    <?php if ($isDefault): ?><span class="badge badge-default-group"><?= te('Par défaut') ?></span><?php endif; ?>
                    <span class="badge <?= !empty($group['is_active']) ? 'badge-green' : 'badge-red' ?>"><?= !empty($group['is_active']) ? te('Actif') : te('Inactif') ?></span>
                </div>
            </div>

            <?php if ($isDefault): ?>
                <p class="ug-card-tagline"><?= te('Inclut automatiquement tous les utilisateurs — socle commun de droits.') ?></p>
            <?php endif; ?>

            <div class="ug-card-stats">
                <div class="ug-stat" title="<?= te('Membres du groupe') ?>">
                    <span class="ug-stat-n"><?= count($members) ?></span>
                    <span class="ug-stat-l"><?= htmlspecialchars(I18n::plural(count($members), 'membre', 'membres')) ?></span>
                </div>
                <div class="ug-stat" title="<?= te('Assistants autorisés') ?>">
                    <span class="ug-stat-n"><?= count($payload['profile_ids']) ?></span>
                    <span class="ug-stat-l"><?= htmlspecialchars(I18n::plural(count($payload['profile_ids']), 'assistant', 'assistants')) ?></span>
                </div>
                <div class="ug-stat" title="<?= te('Outils autorisés') ?>">
                    <span class="ug-stat-n"><?= count($payload['tool_ids']) ?></span>
                    <span class="ug-stat-l"><?= htmlspecialchars(I18n::plural(count($payload['tool_ids']), 'outil', 'outils')) ?></span>
                </div>
                <div class="ug-stat" title="<?= $llmCount === 0 ? te('Aucune liste LLM : groupe neutre') : te('LLMs autorisés') ?>">
                    <span class="ug-stat-n"><?= $llmCount === 0 ? '—' : $llmCount ?></span>
                    <span class="ug-stat-l"><?= $llmCount === 0 ? te('LLM (neutre)') : htmlspecialchars(I18n::plural($llmCount, 'LLM', 'LLMs')) ?></span>
                </div>
            </div>

            <div class="ug-card-meta">
                <?= te('Quota mensuel :') ?>
                <?php if ($quota !== null): ?>
                    <strong><?= te('{n} crédits', ['n' => Format::credits((int)$quota * 1000)]) ?></strong>
                <?php else: ?>
                    <?php /* PAS « illimité » : un groupe sans quota est neutre — le
                             plus élevé des quotas DÉFINIS parmi les groupes du
                             membre s'applique (UserGroup::effectiveQuotaForUserId). */ ?>
                    <strong title="<?= te("Groupe neutre : le plus élevé des quotas des autres groupes du membre s'applique ; sans aucun quota nulle part, la consommation est illimitée.") ?>"><?= te('aucun (neutre)') ?></strong>
                <?php endif; ?>
            </div>

            <div class="ug-card-foot">
                <div class="ug-avatar-stack" aria-hidden="true">
                    <?php foreach (array_slice($members, 0, 6) as $member): ?>
                        <?php if (!empty($member['avatar_url'])): ?>
                            <img class="ug-avatar" src="<?= htmlspecialchars((string)$member['avatar_url']) ?>" alt="" title="<?= htmlspecialchars((string)$member['name']) ?>">
                        <?php else: ?>
                            <span class="ug-avatar ug-avatar-fallback" title="<?= htmlspecialchars((string)$member['name']) ?>"><?= mb_strtoupper(mb_substr((string)$member['name'], 0, 1)) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count($members) > 6): ?>
                        <span class="ug-avatar ug-avatar-more">+<?= count($members) - 6 ?></span>
                    <?php endif; ?>
                </div>
                <span class="ug-manage-hint"><?= te('Gérer →') ?></span>
            </div>
        </article>
        <?php endforeach; ?>

        <?php if (Session::can('groups.create')): ?>
        <button type="button" class="ug-card ug-card-new" id="ugCreateTile">
            <span class="ug-card-new-plus" aria-hidden="true">+</span>
            <span><?= te('Créer un groupe') ?></span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Modale création / édition de groupe -->
<div class="modal" id="ugModal" style="display:none">
    <!-- Formulaire à double mode : l'enregistrement automatique n'est armé
         qu'en ÉDITION (cf. `openModal`). On ne crée pas un groupe à la frappe —
         une création est un acte, elle garde son bouton. -->
    <form method="POST" class="modal-box modal-box-profile-edit ug-modal-box" id="ugForm"
          data-autosave="off" data-autosave-reload="close">
        <?= Csrf::field() ?>

        <header class="profile-modal-header">
            <div class="ug-modal-title-wrap">
                <h3 id="ugModalTitle"><?= te('Nouveau groupe') ?></h3>
                <span class="badge badge-default-group" id="ugModalDefaultBadge" style="display:none">★ <?= te('Groupe par défaut') ?></span>
            </div>
            <button type="button" class="profile-modal-close" id="ugCloseBtn" aria-label="<?= te('Fermer') ?>" title="<?= te('Fermer') ?>">×</button>
        </header>

        <div class="profile-modal-body">
            <section class="profile-section">
                <h4><?= te('Paramètres') ?></h4>
                <p class="profile-section-hint" id="ugDefaultHint" style="display:none">
                    <?= te("Ce groupe inclut automatiquement tous les utilisateurs et ne peut pas être supprimé. Les droits cochés ici s'appliquent à tout le monde.") ?>
                </p>
                <div class="ug-fields-row">
                    <div class="form-group ug-field-name">
                        <label for="ugName"><?= te('Nom du groupe') ?> <span class="ug-required">*</span></label>
                        <input type="text" name="name" id="ugName" class="form-input" placeholder="<?= te('Ex : Support N1') ?>" required>
                    </div>
                    <div class="form-group ug-field-quota">
                        <label for="ugQuota"><?= te('Quota (k crédits/mois)') ?></label>
                        <input type="number" name="credit_quota_k" id="ugQuota" class="form-input" min="0" step="1" placeholder="<?= te('Aucun') ?>">
                        <p class="profile-hint"><?= te("10 = 10 000 crédits par mois et par membre. Vide = groupe neutre : c'est le plus élevé des quotas de ses autres groupes qui s'applique au membre — illimité seulement si aucun n'en a.") ?></p>
                        <?php if (!empty($budgetRepere)): ?>
                        <p class="profile-hint budget-repere-hint"><?= te('Repère budget : ≈ {k} k crédits par compte actif et par mois ({budget} ÷ {n} comptes).', ['k' => (int)$budgetRepere['per_account_k'], 'budget' => Format::eur((float)$budgetRepere['budget_eur']), 'n' => (int)$budgetRepere['accounts']]) ?> <a href="/admin/budget"><?= te('Budget') ?></a></p>
                        <?php endif; ?>
                    </div>
                    <label class="ug-active-check">
                        <input type="checkbox" name="is_active" id="ugActive" value="1" checked>
                        <?= te('Groupe actif') ?>
                    </label>
                </div>
            </section>

            <section class="profile-section">
                <h4><?= te('Membres') ?> <span class="ug-count-pill" id="ugMembersCount">0</span></h4>
                <div class="ug-locked-note" id="ugMembersLocked" style="display:none">
                    <span aria-hidden="true">🔒</span>
                    <span><?= teb("Les {b}{n} utilisateurs{/b} de la plateforme sont membres de ce groupe. L'appartenance est gérée automatiquement (nouveaux comptes inclus).", ['n' => count($users)]) ?></span>
                </div>
                <div id="ugMembersPicker">
                    <input type="hidden" name="members_present" value="1" id="ugMembersPresent">
                    <input type="text" class="form-input ug-search" id="ugMemberSearch" placeholder="<?= te('Rechercher un utilisateur (nom ou email)…') ?>" autocomplete="off">
                    <div class="ug-check-list ug-member-list" id="ugMemberList">
                        <?php foreach ($users as $user): $uid = (int)$user['id']; ?>
                            <label class="ug-check-item ug-member-item" data-search="<?= htmlspecialchars(mb_strtolower($user['name'] . ' ' . $user['email'])) ?>">
                                <input type="checkbox" name="member_ids[]" value="<?= $uid ?>">
                                <?php if (!empty($user['avatar_url'])): ?>
                                    <img class="ug-avatar" src="<?= htmlspecialchars((string)$user['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <span class="ug-avatar ug-avatar-fallback"><?= mb_strtoupper(mb_substr((string)$user['name'], 0, 1)) ?></span>
                                <?php endif; ?>
                                <span class="ug-member-id">
                                    <span class="ug-member-name"><?= htmlspecialchars($user['name']) ?></span>
                                    <span class="ug-member-email"><?= htmlspecialchars($user['email']) ?></span>
                                </span>
                                <?php if (empty($user['is_active'])): ?><span class="badge badge-red"><?= te('Inactif') ?></span><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="ug-empty-search" id="ugMemberNoResult" style="display:none"><?= te('Aucun utilisateur ne correspond à la recherche.') ?></p>
                </div>
            </section>

            <section class="profile-section">
                <h4><?= te("Droits d'accès") ?></h4>
                <p class="profile-section-hint"><?= te("Les droits des groupes actifs s'additionnent pour un même utilisateur.") ?></p>
                <div class="ug-access-grid">
                    <div class="ug-access-col">
                        <div class="ug-access-head">
                            <span class="ug-access-title"><?= te('Assistants') ?></span>
                            <span class="ug-access-tools"><button type="button" class="ug-mini-link" data-check-all="ugListProfiles"><?= te('Tout') ?></button><button type="button" class="ug-mini-link" data-check-none="ugListProfiles"><?= te('Aucun') ?></button></span>
                        </div>
                        <?php if (empty($eligibleProfiles)): ?>
                            <p class="ug-empty-list"><?= te("Aucun assistant d'organisation actif.") ?></p>
                        <?php else: ?>
                            <div class="ug-check-list" id="ugListProfiles">
                                <?php foreach ($eligibleProfiles as $profile): ?>
                                    <label class="ug-check-item">
                                        <input type="checkbox" name="profile_ids[]" value="<?= (int)$profile['id'] ?>">
                                        <span><?= htmlspecialchars($profile['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ug-access-col">
                        <div class="ug-access-head">
                            <span class="ug-access-title"><?= te('Outils') ?></span>
                            <span class="ug-access-tools"><button type="button" class="ug-mini-link" data-check-all="ugListTools"><?= te('Tout') ?></button><button type="button" class="ug-mini-link" data-check-none="ugListTools"><?= te('Aucun') ?></button></span>
                        </div>
                        <?php if (empty($tools)): ?>
                            <p class="ug-empty-list"><?= te('Aucun outil disponible.') ?></p>
                        <?php else: ?>
                            <div class="ug-check-list" id="ugListTools">
                                <?php foreach ($tools as $tool): ?>
                                    <label class="ug-check-item">
                                        <input type="checkbox" name="tool_ids[]" value="<?= (int)$tool['id'] ?>">
                                        <span>
                                            <?= htmlspecialchars($tool['name']) ?>
                                            <span class="ug-item-muted">(<?= htmlspecialchars($tool['type']) ?>)</span>
                                            <?php if (empty($tool['is_active'])): ?><span class="ug-item-warn"><?= te('inactif') ?></span><?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ug-access-col">
                        <div class="ug-access-head">
                            <span class="ug-access-title"><?= te('LLMs') ?></span>
                            <span class="ug-access-tools"><button type="button" class="ug-mini-link" data-check-all="ugListLlms"><?= te('Tout') ?></button><button type="button" class="ug-mini-link" data-check-none="ugListLlms"><?= te('Aucun') ?></button></span>
                        </div>
                        <p class="profile-hint" style="margin:0 0 6px"><?= te('Aucune coche = groupe neutre côté LLM.') ?></p>
                        <?php if (empty($llms)): ?>
                            <p class="ug-empty-list"><?= te('Aucun LLM configuré.') ?></p>
                        <?php else: ?>
                            <div class="ug-check-list" id="ugListLlms">
                                <?php foreach ($llms as $llmKey => $llmLabel): ?>
                                    <label class="ug-check-item">
                                        <input type="checkbox" name="llm_keys[]" value="<?= htmlspecialchars((string)$llmKey) ?>">
                                        <span><?= htmlspecialchars((string)$llmLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <footer class="profile-modal-footer">
            <button type="button" class="btn-danger-link" id="ugDeleteBtn"><?= te('Supprimer le groupe') ?></button>
            <div class="profile-modal-footer-actions">
                <span id="ugAutosaveHint" style="display:none">
                    <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                </span>
                <button type="button" class="btn-secondary" id="ugCancelBtn"><?= te('Annuler') ?></button>
                <?php /* Bouton de CRÉATION seulement : masqué en édition par
                         `openModal`, où l'auto-save prend le relais. Libellé
                         posé ici pour le rendu sans JavaScript. */ ?>
                <button type="submit" class="btn-primary" id="ugSubmitBtn"><?= te('Créer le groupe') ?></button>
            </div>
        </footer>
    </form>
</div>

<form method="POST" id="ugDeleteForm" style="display:none"><?= Csrf::field() ?></form>

<script>
(() => {
    const GROUPS = <?= json_encode($groupsPayload, JSON_UNESCAPED_UNICODE) ?>;
    // Droits du rôle sur les groupes (Personnes → Rôles) : la fiche d'un
    // groupe s'ouvre en lecture seule sans « Modifier », sans bouton de
    // suppression sans « Supprimer ». Le serveur refuse de toute façon.
    const UG_CAN = <?= json_encode(['edit' => Session::can('groups.edit'), 'delete' => Session::can('groups.delete')]) ?>;
    // Substituants {nom} des textes traduits par tj().
    const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => ((vars && k in vars) ? String(vars[k]) : m));

    const modal        = document.getElementById('ugModal');
    const form         = document.getElementById('ugForm');
    const title        = document.getElementById('ugModalTitle');
    const defaultBadge = document.getElementById('ugModalDefaultBadge');
    const defaultHint  = document.getElementById('ugDefaultHint');
    const nameInput    = document.getElementById('ugName');
    const quotaInput   = document.getElementById('ugQuota');
    const activeInput  = document.getElementById('ugActive');
    const membersLocked  = document.getElementById('ugMembersLocked');
    const membersPicker  = document.getElementById('ugMembersPicker');
    const membersPresent = document.getElementById('ugMembersPresent');
    const membersCount   = document.getElementById('ugMembersCount');
    const memberSearch   = document.getElementById('ugMemberSearch');
    const memberNoResult = document.getElementById('ugMemberNoResult');
    const deleteBtn    = document.getElementById('ugDeleteBtn');
    const deleteForm   = document.getElementById('ugDeleteForm');
    const submitBtn    = document.getElementById('ugSubmitBtn');
    const cancelBtn    = document.getElementById('ugCancelBtn');
    const autosaveHint = document.getElementById('ugAutosaveHint');

    const memberBoxes = () => form.querySelectorAll('input[name="member_ids[]"]');
    const boxesOf = (name) => form.querySelectorAll('input[name="' + name + '"]');

    function setChecked(name, values) {
        const set = new Set((values || []).map(String));
        boxesOf(name).forEach((box) => { box.checked = set.has(box.value); });
    }

    function refreshMembersCount() {
        let n = 0;
        memberBoxes().forEach((box) => { if (box.checked) n++; });
        membersCount.textContent = String(n);
    }

    function resetSearch() {
        memberSearch.value = '';
        filterMembers();
    }

    function filterMembers() {
        const q = memberSearch.value.trim().toLowerCase();
        let visible = 0;
        form.querySelectorAll('.ug-member-item').forEach((item) => {
            const match = !q || (item.dataset.search || '').includes(q);
            item.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        memberNoResult.style.display = visible === 0 ? '' : 'none';
    }

    function openModal(group) {
        const isEdit    = !!group;
        const isDefault = !!(group && group.is_default);

        form.action = isEdit ? '/admin/user-groups/' + group.id : '/admin/user-groups';
        title.textContent = isEdit ? fill(<?= tj('Modifier — {nom}') ?>, { nom: group.label || group.name }) : <?= tj('Nouveau groupe') ?>;
        // En édition, le bouton disparaît au profit de l'enregistrement
        // automatique (armé plus bas, une fois les champs remplis) ; en
        // création il reste, car créer un groupe est un acte volontaire.
        submitBtn.style.display = isEdit ? 'none' : '';
        submitBtn.textContent = <?= tj('Créer le groupe') ?>;
        cancelBtn.textContent = isEdit ? <?= tj('Fermer') ?> : <?= tj('Annuler') ?>;
        autosaveHint.style.display = isEdit ? '' : 'none';

        nameInput.value   = isEdit ? group.name : '';
        quotaInput.value  = isEdit && group.quota !== null ? group.quota : '';
        activeInput.checked = isEdit ? group.is_active : true;

        setChecked('profile_ids[]', isEdit ? group.profile_ids : []);
        setChecked('tool_ids[]',    isEdit ? group.tool_ids : []);
        setChecked('llm_keys[]',    isEdit ? group.llm_keys : []);
        setChecked('member_ids[]',  isEdit ? group.member_ids : []);

        // Groupe par défaut : membres verrouillés (gérés automatiquement),
        // suppression impossible.
        defaultBadge.style.display = isDefault ? '' : 'none';
        defaultHint.style.display  = isDefault ? '' : 'none';
        membersLocked.style.display = isDefault ? '' : 'none';
        membersPicker.style.display = isDefault ? 'none' : '';
        membersPresent.disabled = isDefault;
        memberBoxes().forEach((box) => { box.disabled = isDefault; });
        membersCount.style.display = isDefault ? 'none' : '';
        deleteBtn.style.display = (isEdit && !isDefault && UG_CAN.delete) ? '' : 'none';
        // Lecture seule : champs grisés (les boutons d'interface restent).
        const readOnly = isEdit && !UG_CAN.edit;
        Array.prototype.forEach.call(form.elements, (el) => {
            if (el.type === 'hidden' || (el.tagName === 'BUTTON' && el.type === 'button')) return;
            if (readOnly) { el.dataset.ugRo = el.disabled ? '0' : '1'; el.disabled = true; }
            else if (el.dataset.ugRo === '1') { el.disabled = false; delete el.dataset.ugRo; }
        });
        deleteBtn.dataset.groupId = isEdit ? String(group.id) : '';
        deleteBtn.dataset.groupName = isEdit ? group.name : '';

        resetSearch();
        refreshMembersCount();
        modal.style.display = 'flex';
        form.querySelector('.profile-modal-body').scrollTop = 0;
        nameInput.focus();
        // Armé en tout dernier : `setChecked` et les affectations ci-dessus
        // seraient sinon prises pour des modifications de l'utilisateur.
        form.dataset.autosave = (isEdit && UG_CAN.edit) ? 'on' : 'off';
    }

    function closeModal() {
        const hide = () => { modal.style.display = 'none'; };
        // La carte du groupe derrière porte son nom, son quota et ses
        // compteurs : sans rechargement elle resterait sur les anciennes
        // valeurs après une modification.
        if (window.MiaAutosave) {
            window.MiaAutosave.closeAndReload(form, hide);
        } else {
            hide();
        }
    }

    // Ouverture — cards, bouton header, tuile de création
    document.querySelectorAll('.ug-card[data-group-id]').forEach((card) => {
        const open = () => {
            const group = GROUPS[card.dataset.groupId];
            if (group) openModal(group);
        };
        card.addEventListener('click', open);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
    });
    document.getElementById('ugCreateBtn')?.addEventListener('click', () => openModal(null));
    document.getElementById('ugCreateTile')?.addEventListener('click', () => openModal(null));

    // Fermeture — croix, annuler, clic hors modale, Échap
    document.getElementById('ugCloseBtn').addEventListener('click', closeModal);
    document.getElementById('ugCancelBtn').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
    });

    // Suppression (groupes non-défaut uniquement)
    deleteBtn.addEventListener('click', () => {
        const gid = deleteBtn.dataset.groupId;
        if (!gid) return;
        if (!confirm(fill(<?= tj('Supprimer le groupe « {nom} » ?') ?>, { nom: deleteBtn.dataset.groupName })
            + '\n' + <?= tj('Les membres resteront actifs mais perdront les droits de ce groupe.') ?>)) return;
        deleteForm.action = '/admin/user-groups/' + gid + '/delete';
        deleteForm.submit();
    });

    // Compteur de membres + recherche
    memberBoxes().forEach((box) => box.addEventListener('change', refreshMembersCount));
    memberSearch.addEventListener('input', filterMembers);

    // Raccourcis Tout / Aucun
    form.querySelectorAll('[data-check-all], [data-check-none]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const listId = btn.dataset.checkAll || btn.dataset.checkNone;
            const list = document.getElementById(listId);
            if (!list) return;
            list.querySelectorAll('input[type="checkbox"]').forEach((box) => {
                box.checked = !!btn.dataset.checkAll;
            });
        });
    });
})();
</script>
</body>
</html>
