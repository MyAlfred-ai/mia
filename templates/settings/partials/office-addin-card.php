<?php
/*
 * Carte « Claude dans Office » de Réglages → Comptes connectés.
 * $officeCard : OfficeAddinController::cardFor() (non null ici).
 * $officeNewToken : jeton tout juste généré sans script (montré une fois).
 */
$officeToken = $officeCard['token'] ?? null;
$officeApps  = (array)($officeCard['apps'] ?? array_keys(OfficeAddin::APPS));
?>
<div class="card" id="office-addin">
    <div style="display:flex;align-items:flex-start;gap:14px">
        <div class="office-card-icons"><?= OfficeAddin::appIcons($officeApps, 26) ?></div>
        <div style="flex:1;min-width:0">
            <h3 style="margin:0 0 4px;font-size:15px"><?= te('Claude dans Office') ?><?= MajorFeatures::anchorDot('office-addin') ?></h3>
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px">
                <?= teb('L\'add-in {b}Claude{/b} de {b}{apps}{/b} passe par MIA : aucun compte Claude à créer, ses réponses comptent dans {b}vos crédits MIA{/b}.', ['apps' => OfficeAddin::appsSentence($officeApps)]) ?>
            </p>

            <?php if (!empty($officeCard['help_profile_id'])): ?>
                <form method="POST" action="/chat/new" class="office-guide" id="officeAddinGuide"
                      data-question="<?= te('Comment relier Claude à Excel, Word ou PowerPoint ? Guide-moi pas à pas, depuis le début.') ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="profile_id" value="<?= (int)$officeCard['help_profile_id'] ?>">
                    <span class="office-guide-text"><?= teb('{b}Première fois ?{/b} L\'assistant « MIA : mode d\'emploi » vous guide étape par étape, captures d\'écran à l\'appui.') ?></span>
                    <button type="submit" class="btn-primary office-guide-btn"><?= te('Me guider pas à pas') ?></button>
                </form>
            <?php endif; ?>

            <?php if (!empty($officeCard['entra'])): ?>
                <p style="font-size:13px;margin:0 0 10px">
                    <?= teb('{b}Avec votre compte Microsoft{/b} : si votre organisation a déployé l\'add-in, ouvrez {apps} → {b}Accueil → Compléments → Claude{/b}. Rien à saisir.', ['apps' => OfficeAddin::appsSentence($officeApps, true)]) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($officeCard['tokens'])): ?>
                <p style="font-size:13px;margin:0 0 8px">
                    <?= teb('{b}Avec un jeton personnel{/b} : dans l\'add-in, choisissez {b}Cloud provider or gateway → Gateway{/b}, puis collez l\'adresse et votre jeton — le même jeton sert dans chaque application.') ?>
                </p>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;font-size:13px">
                    <span class="slack-muted"><?= te('Adresse :') ?></span>
                    <code id="officeAddinGateway" style="word-break:break-all"><?= htmlspecialchars((string)$officeCard['gateway']) ?></code>
                    <button type="button" class="btn-secondary office-copy" data-copy-target="officeAddinGateway" style="font-size:12px"><?= te('Copier') ?></button>
                </div>

                <div id="officeAddinNewToken" <?= empty($officeNewToken) ? 'hidden' : '' ?> style="margin:0 0 12px;padding:12px;border:1px solid var(--accent);border-radius:8px">
                    <p style="font-size:13px;margin:0 0 6px"><strong><?= te('Votre jeton — copiez-le maintenant, il ne sera plus affiché :') ?></strong></p>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <code id="officeAddinTokenValue" style="word-break:break-all"><?= htmlspecialchars((string)($officeNewToken ?? '')) ?></code>
                        <button type="button" class="btn-secondary office-copy" data-copy-target="officeAddinTokenValue" style="font-size:12px"><?= te('Copier') ?></button>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <?php if ($officeToken !== null): ?>
                        <span class="badge badge-accent"><?= te('Jeton actif') ?></span>
                        <span style="font-size:12px;color:var(--text-muted)">
                            <?= htmlspecialchars((string)$officeToken['hint']) ?>
                            · <?= te('créé le {date}', ['date' => Format::dateFr((string)$officeToken['created_at'])]) ?>
                            <?php if (!empty($officeToken['last_used_at'])): ?>
                                · <?= te('utilisé le {date}', ['date' => Format::dateFr((string)$officeToken['last_used_at'])]) ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <form method="POST" action="/settings/office/token" class="office-token-form" data-office-action="issue" style="margin:0"
                          <?= $officeToken !== null ? 'data-confirm="' . te('Générer un nouveau jeton ? L\'ancien cessera aussitôt de fonctionner.') . '"' : '' ?>>
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn-primary" style="font-size:13px"><?= $officeToken !== null ? te('Remplacer mon jeton') : te('Générer mon jeton') ?></button>
                    </form>
                    <?php if ($officeToken !== null): ?>
                        <form method="POST" action="/settings/office/token/delete" class="office-token-form" data-office-action="revoke" style="margin:0"
                              data-confirm="<?= te('Révoquer votre jeton ? L\'add-in qui l\'utilise ne répondra plus.') ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Révoquer') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <p id="officeAddinStatus" role="status" aria-live="polite" style="font-size:12.5px;margin:8px 0 0"></p>
            <?php endif; ?>

            <?php if (!empty($officeCard['models'])): ?>
                <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0">
                    <?= te('Modèles ouverts à votre compte : {list}', ['list' => implode(', ', array_map('strval', $officeCard['models']))]) ?>
                </p>
            <?php else: ?>
                <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0">
                    <?= te('Aucun modèle Claude n\'est ouvert à votre compte pour l\'instant : demandez-le à votre administrateur.') ?>
                </p>
            <?php endif; ?>
            <?php $officeUsage = $officeCard['usage'] ?? null; ?>
            <?php if (is_array($officeUsage) && (int)$officeUsage['requests'] > 0): ?>
                <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0">
                    <?= te('Ce mois-ci : {n} crédits pour {demandes}', [
                        'n'        => Format::credits((int)$officeUsage['credits']),
                        'demandes' => I18n::plural((int)$officeUsage['requests'], '{n} demande', '{n} demandes'),
                    ]) ?>
                    · <a href="/settings/usage"><?= te('Voir mon usage') ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Jeton personnel : généré ou révoqué sans quitter la page, montré une fois.
(function () {
    var card = document.getElementById('office-addin');
    if (!card) return;
    var status = document.getElementById('officeAddinStatus');
    card.querySelectorAll('.office-copy').forEach(function (btn) {
        var label = btn.textContent;
        btn.addEventListener('click', function () {
            var cible = document.getElementById(btn.getAttribute('data-copy-target') || '');
            if (!cible || !navigator.clipboard || !window.isSecureContext) return;
            navigator.clipboard.writeText(cible.textContent).then(function () {
                btn.textContent = <?= tj('Copié ✓') ?>;
                setTimeout(function () { btn.textContent = label; }, 1800);
            });
        });
    });
    // « Me guider pas à pas » : la question attend dans la zone de saisie de la
    // conversation ouverte avec l'assistant d'aide (même relais que le choix
    // d'un assistant depuis le chat).
    var guide = document.getElementById('officeAddinGuide');
    if (guide) {
        guide.addEventListener('submit', function () {
            try { sessionStorage.setItem('composerDraft', guide.getAttribute('data-question') || ''); } catch (e) {}
        });
    }
    card.querySelectorAll('.office-token-form').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var question = form.getAttribute('data-confirm');
            if (question && !window.confirm(question)) {
                ev.preventDefault();
                return;
            }
            if (!window.fetch) return;
            ev.preventDefault();
            var body = new FormData(form);
            fetch(form.action, { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.token) {
                        // Le jeton reste affiché ; la page se rechargera au prochain passage.
                        document.getElementById('officeAddinTokenValue').textContent = data.token;
                        document.getElementById('officeAddinNewToken').hidden = false;
                    } else if (data && data.ok) {
                        window.location.reload();
                        return;
                    }
                    if (status && data && data.message) {
                        status.textContent = data.message;
                    }
                })
                .catch(function () { form.submit(); });
        });
    });
})();
</script>
