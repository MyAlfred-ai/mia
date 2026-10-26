<?php
// Modes de connexion du compte, lus une fois pour toute la page (badge
// d'en-tête et carte Sécurité). Ils vivent dans `user_identities` : la colonne
// `google_id` ne savait décrire ni un compte Microsoft, ni un compte portant
// à la fois un mot de passe et une connexion externe.
$userProviders = UserIdentity::providersForUser((int)($user['id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Réglages —') ?> <?= htmlspecialchars(Settings::get('app_name', 'MIA')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
    <style>
        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 40px; width: 100%; box-sizing: border-box; }
        .password-toggle {
            position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
            background: none; border: 0; padding: 6px; cursor: pointer;
            color: var(--text-muted); display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
        }
        .password-toggle:hover { color: var(--text-color, #111); background: var(--bg-surface); }
        /* Carte de renvoi « Ce que MIA sait de vous » : le bouton à droite de la
           tête, en dessous sur petit écran. */
        .profile-pointer .card-head { margin-bottom: 0; align-items: center; }
        .profile-pointer .btn-secondary { flex-shrink: 0; text-decoration: none; white-space: nowrap; }
        @media (max-width: 640px) {
            .profile-pointer .card-head { flex-wrap: wrap; }
            .profile-pointer .btn-secondary { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Réglages'); $page = 'profile'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<!-- Contenu -->
<div class="admin-content">
    <div style="max-width:680px">
        <h1 style="margin-bottom:6px"><?= te('Mon profil') ?></h1>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:28px"><?= te('Gérez vos informations personnelles et votre sécurité.') ?></p>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-bottom:20px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Carte : informations du profil -->
        <div class="card" style="margin-bottom:20px">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--border)">
                <!-- Avatar -->
                <div style="position:relative;flex-shrink:0">
                    <?php if (!empty($user['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar_url']) ?>"
                             alt="<?= te('Avatar') ?>"
                             id="avatarPreview"
                             style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">
                    <?php else: ?>
                        <div id="avatarPreview"
                             style="width:72px;height:72px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#fff">
                            <?= mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Infos rapides -->
                <div>
                    <div style="font-size:18px;font-weight:600"><?= htmlspecialchars($user['name'] ?? '') ?></div>
                    <div style="font-size:13px;color:var(--text-muted);margin-top:3px"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                        <span class="badge <?= ($user['role'] ?? '') === 'admin' ? 'badge-accent' : 'badge-gray' ?>">
                            <?= htmlspecialchars(Role::label((string)($user['role'] ?? 'user'))) ?>
                        </span>
                        <?php if ($userProviders !== []): ?>
                            <span class="badge badge-gray"><?= te('Compte') ?> <?= htmlspecialchars(UserIdentity::authLabel($userProviders, false)) ?></span>
                        <?php else: ?>
                            <span class="badge badge-gray"><?= te('Compte local') ?></span>
                        <?php endif; ?>
                        <span style="font-size:11px;color:var(--text-muted);align-self:center">
                            <?= te('Membre depuis le') ?> <?= date('d/m/Y', strtotime($user['created_at'] ?? 'now')) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Formulaire de mise à jour -->
            <form method="POST" action="/settings/profile" enctype="multipart/form-data" data-autosave>
                <?= Csrf::field() ?>

                <div style="display:grid;gap:16px">

                    <label>
                        <span style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Nom complet') ?></span>
                        <input type="text" name="name" class="form-input"
                               value="<?= htmlspecialchars($user['name'] ?? '') ?>"
                               placeholder="<?= te('Votre nom') ?>" required>
                    </label>

                    <label>
                        <span style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Adresse e-mail') ?></span>
                        <input type="email" name="email" class="form-input"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                               placeholder="<?= te('votre@email.com') ?>" required>
                    </label>

                    <!-- Langue de l'interface : le MÊME réglage que le sélecteur
                         flottant en haut à droite (users.lang, POST /settings/lang,
                         rechargement dans la nouvelle langue) — comportement dans
                         user-menu.js (data-lang-select). Hors du formulaire
                         auto-enregistré, exprès : pas de `name` (jamais posté avec
                         le profil) et data-autosave-skip (le changement ne déclenche
                         pas l'enregistrement du profil). -->
                    <div>
                        <label for="profileLang" style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Langue de l’interface') ?></label>
                        <select id="profileLang" class="form-input" data-lang-select data-autosave-skip>
                            <?php foreach (I18n::LOCALES as $code => $infos): ?>
                                <option value="<?= htmlspecialchars($code) ?>"<?= I18n::locale() === $code ? ' selected' : '' ?>><?= htmlspecialchars($infos['drapeau'] . ' ' . $infos['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p style="font-size:12px;color:var(--text-muted);margin:5px 0 0"><?= te('Suit votre compte, sur tous vos appareils. Les assistants, eux, répondent dans la langue de votre message.') ?></p>
                    </div>

                    <!-- Photo de profil : un fichier choisi OU une adresse collée,
                         deux gestes équivalents (AvatarStorage) — l'image est
                         copiée chez MIA dans les deux cas. Le champ d'adresse
                         n'affiche JAMAIS le chemin local de la photo enregistrée :
                         un champ de type `url` le déclarait invalide et bloquait
                         tout enregistrement, fichier compris. Vide = garder la
                         photo. `data-autosave-silent` : il part avec le
                         formulaire mais ne déclenche rien à la frappe — le script
                         l'envoie au collage, à Entrée ou en quittant le champ,
                         jamais sur une adresse à moitié tapée. -->
                    <?php $avatarCurrent = (string)($user['avatar_url'] ?? ''); ?>
                    <div>
                        <span style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Photo de profil') ?></span>
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <!-- Upload fichier -->
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                <div style="border:1.5px dashed var(--border);border-radius:8px;padding:10px 16px;font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex:1">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <span id="avatarFileLabel"><?= te('Choisir une image (JPEG, PNG, WebP — max 2 Mo)') ?></span>
                                </div>
                                <input type="file" name="avatar_file" id="avatarFileInput"
                                       accept="image/jpeg,image/png,image/webp,image/gif"
                                       style="display:none">
                            </label>
                            <!-- URL alternative -->
                            <div style="display:flex;align-items:center;gap:8px">
                                <label for="avatarUrlInput" style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= te('Ou URL d\'image :') ?></label>
                                <input type="text" name="avatar_url" id="avatarUrlInput" class="form-input"
                                       inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false"
                                       data-autosave-silent
                                       value="<?= htmlspecialchars(AvatarStorage::urlFieldValue($avatarCurrent)) ?>"
                                       placeholder="https://…/photo.jpg" style="flex:1;font-size:13px">
                            </div>
                            <p style="font-size:12px;color:var(--text-muted);margin:0"><?= te('Collez l’adresse d’une image en ligne : MIA en garde une copie, qui reste affichée même si l’original disparaît.') ?></p>
                            <!-- Retrait : drapeau vidé après chaque enregistrement
                                 (data-autosave-reset), posé par le bouton. -->
                            <input type="hidden" name="remove_avatar" id="avatarRemoveFlag" value="" data-autosave-reset>
                            <div>
                                <button type="button" class="btn-secondary" id="avatarRemoveBtn"<?= $avatarCurrent === '' ? ' style="display:none"' : '' ?>><?= te('Retirer la photo') ?></button>
                            </div>
                        </div>
                    </div>

                </div>

                <div style="margin-top:20px;display:flex;justify-content:flex-end">
                    <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                </div>
            </form>
        </div>

        <!-- Ce que MIA sait de vous (votre contexte + souvenirs « MIA pour vous »)
             vit dans Préférences IA, sous « Votre contexte » : une seule liste, une
             seule porte. Mon profil y RENVOIE au lieu de dupliquer l'éditeur —
             deux exemplaires obligeaient la doc à expliquer « deux portes ». -->
        <div class="card profile-pointer" style="margin-bottom:20px">
            <div class="card-head">
                <span class="card-ic" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                </span>
                <div class="card-head-text">
                    <h3><?= te('Ce que MIA sait de vous') ?></h3>
                    <p><?= te('Votre contexte et vos souvenirs « MIA pour vous » se règlent dans Préférences IA, avec le style des réponses et la voix.') ?></p>
                </div>
                <a href="/settings/ai#contexte" class="btn-secondary"><?= te('Ouvrir les Préférences IA') ?></a>
            </div>
        </div>

        <!-- Carte : sécurité / mot de passe -->
        <?php
        // Ce qui décide de l'affichage du formulaire, c'est la PRÉSENCE d'un
        // mot de passe, pas la forme de `google_id` : un compte peut porter à
        // la fois un mot de passe et une connexion externe.
        $hasPassword = !empty($user['password_hash']);
        ?>
        <div class="card">
            <h3 style="margin-bottom:4px;font-size:15px"><?= te('Sécurité') ?></h3>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px">
                <?php if ($hasPassword): ?>
                    <?= te('Modifiez votre mot de passe de connexion.') ?>
                <?php elseif ($userProviders !== []): ?>
                    <?= te('Votre compte se connecte via {methode}. La gestion du mot de passe se fait depuis ce compte.', ['methode' => UserIdentity::authLabel($userProviders, false)]) ?>
                <?php else: ?>
                    <?= te('Aucun mot de passe n\'est défini sur ce compte. Contactez un administrateur.') ?>
                <?php endif; ?>
            </p>

            <?php if ($hasPassword): ?>
            <form method="POST" action="/settings/password">
                <?= Csrf::field() ?>
                <div style="display:grid;gap:14px">
                    <label>
                        <span style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Mot de passe actuel') ?></span>
                        <div class="password-wrapper">
                            <input type="password" name="current_password" class="form-input"
                                   placeholder="••••••••" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-toggle-password aria-label="<?= te('Afficher le mot de passe') ?>">
                                <svg class="eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <label>
                            <span style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Nouveau mot de passe') ?></span>
                            <div class="password-wrapper">
                                <input type="password" name="new_password" class="form-input"
                                       placeholder="<?= te('Min. 8 caractères') ?>" autocomplete="new-password" required minlength="8">
                                <button type="button" class="password-toggle" data-toggle-password aria-label="<?= te('Afficher le mot de passe') ?>">
                                    <svg class="eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </label>
                        <label>
                            <span style="font-size:13px;font-weight:500;display:block;margin-bottom:5px"><?= te('Confirmer') ?></span>
                            <div class="password-wrapper">
                                <input type="password" name="confirm_password" class="form-input"
                                       placeholder="••••••••" autocomplete="new-password" required minlength="8">
                                <button type="button" class="password-toggle" data-toggle-password aria-label="<?= te('Afficher le mot de passe') ?>">
                                    <svg class="eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </label>
                    </div>
                </div>
                <div style="margin-top:20px;display:flex;justify-content:flex-end">
                    <button type="submit" class="btn-primary"><?= te('Changer le mot de passe') ?></button>
                </div>
            </form>
            <?php else: ?>
                <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <?= te('Connexion gérée par Google — aucun mot de passe local.') ?>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /max-width -->
</div><!-- /admin-content -->

<script>
// Toggle visibilité mot de passe
document.querySelectorAll('[data-toggle-password]').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        const show  = btn.querySelector('.eye-show');
        const hide  = btn.querySelector('.eye-hide');
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        show.style.display = visible ? '' : 'none';
        hide.style.display = visible ? 'none' : '';
        btn.setAttribute('aria-label', visible ? <?= tj('Afficher le mot de passe') ?> : <?= tj('Masquer le mot de passe') ?>);
    });
});

// Photo de profil — trois gestes, un seul enregistrement : choisir un fichier,
// coller une adresse, retirer la photo. Le serveur renvoie la photo réellement
// enregistrée (`avatar_url`), et TOUS ses emplacements suivent : aperçu, pied de
// la barre latérale, avatar du header.
(function () {
    const form       = document.querySelector('form[action="/settings/profile"]');
    const input      = document.getElementById('avatarFileInput');
    const label      = document.getElementById('avatarFileLabel');
    const urlField   = document.getElementById('avatarUrlInput');
    const removeBtn  = document.getElementById('avatarRemoveBtn');
    const removeFlag = document.getElementById('avatarRemoveFlag');
    if (!form || !input) return;

    const FILE_LABEL = <?= tj('Choisir une image (JPEG, PNG, WebP — max 2 Mo)') ?>;
    const PREVIEW_IMG = 'width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--border)';
    const PREVIEW_FALLBACK = 'width:72px;height:72px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#fff';

    function save() {
        if (window.MiaAutosave) window.MiaAutosave.save(form, 0);
    }

    function nameWords() {
        const field = form.querySelector('input[name="name"]');
        return ((field && field.value) || '').trim().split(/\s+/);
    }
    function initials() {
        const w = nameWords();
        return ((w[0] || '').charAt(0) + (w[1] || '').charAt(0)).toUpperCase() || '?';
    }

    function paintPreview(url) {
        const cur = document.getElementById('avatarPreview');
        if (!cur) return;
        if (url) {
            if (cur.tagName === 'IMG') { cur.src = url; return; }
            const img = document.createElement('img');
            img.id = 'avatarPreview';
            img.alt = <?= tj('Avatar') ?>;
            img.style.cssText = PREVIEW_IMG;
            img.src = url;
            cur.replaceWith(img);
        } else if (cur.tagName === 'IMG') {
            const div = document.createElement('div');
            div.id = 'avatarPreview';
            div.style.cssText = PREVIEW_FALLBACK;
            div.textContent = ((nameWords()[0] || '?').charAt(0) || '?').toUpperCase();
            cur.replaceWith(div);
        }
    }

    // Emplacements rendus par les partials du compte : image ↔ pastille d'initiales.
    const SLOTS = [
        { img: 'user-avatar', fallback: 'user-avatar-fallback', fallbackClass: 'user-avatar-fallback' },
    ];
    function paintAccount(url) {
        SLOTS.forEach(slot => {
            if (url) {
                document.querySelectorAll('img.' + slot.img).forEach(img => { img.src = url; });
                document.querySelectorAll('.' + slot.fallback).forEach(el => {
                    const img = document.createElement('img');
                    img.className = slot.img;
                    img.alt = '';
                    img.src = url;
                    el.replaceWith(img);
                });
            } else {
                document.querySelectorAll('img.' + slot.img).forEach(img => {
                    const span = document.createElement('span');
                    span.className = slot.fallbackClass;
                    span.setAttribute('aria-hidden', 'true');
                    span.textContent = initials();
                    img.replaceWith(span);
                });
            }
        });
    }

    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) return;
        label.textContent = file.name;
        // Le fichier l'emporte : une adresse laissée dans le champ ne repart pas avec lui.
        if (urlField) urlField.value = '';
        if (removeFlag) removeFlag.value = '';
        paintPreview(URL.createObjectURL(file));
    });

    if (urlField) {
        // Une adresse COLLÉE part aussitôt ; tapée, elle part en quittant le
        // champ ou à Entrée (soumission interceptée par l'auto-save). Jamais à
        // chaque frappe : « https://exe » serait téléchargée pour rien.
        urlField.addEventListener('paste', () => {
            setTimeout(() => { if (urlField.value.trim() !== '') save(); }, 0);
        });
        urlField.addEventListener('change', () => {
            if (urlField.value.trim() !== '') save();
        });
    }

    if (removeBtn && removeFlag) {
        removeBtn.addEventListener('click', () => {
            removeFlag.value = '1';
            if (urlField) urlField.value = '';
            input.value = '';
            save();
        });
    }

    // La réponse dit quelle photo est enregistrée. Le champ d'adresse est
    // recalé dessus (vide pour une photo copiée chez MIA) : laissé tel quel, il
    // repartirait au prochain enregistrement et re-téléchargerait l'image. Seul
    // un import refusé garde l'adresse saisie, pour qu'on la corrige.
    document.addEventListener('mia:autosaved', function (e) {
        if (e.target !== form) return;
        const data = e.detail?.data;
        if (!data || data.avatar_url === undefined) return;
        const url = String(data.avatar_url || '');

        if (urlField && !data.avatar_failed) {
            urlField.value = /^https?:\/\//i.test(url) ? url : '';
        }
        paintPreview(url);
        paintAccount(url);
        if (removeBtn) removeBtn.style.display = url ? '' : 'none';
        if (label) label.textContent = FILE_LABEL;
    });
})();
</script>
</body>
</html>
