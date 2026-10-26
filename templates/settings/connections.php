<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" <?= DisplayPreferences::htmlAttributes() ?>>
<head>
<?php require ROOT . '/templates/partials/head-meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="icon" href="<?= htmlspecialchars(Settings::get('logo_url', '/assets/img/logo.png')) ?>">
    <title><?= te('Comptes connectés —') ?> <?= htmlspecialchars(Settings::get('app_name', 'myLLM')) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= (int)(@filemtime(ROOT . '/public/assets/css/main.css') ?: 1) ?>">
    <?php require ROOT . '/templates/partials/theme-vars.php'; ?>
</head>
<body class="admin-page">

<?php $navVariant = 'settings'; $navTitle = t('Comptes connectés'); $page = 'connections'; require ROOT . '/templates/partials/admin-nav.php'; ?>

<!-- Contenu -->
<div class="admin-content">
    <div style="max-width:680px">
        <h1 style="margin-bottom:6px"><?= te('Comptes connectés') ?></h1>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:28px"><?= te('Connectez vos services externes pour enrichir les capacités de vos assistants.') ?></p>

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

        <?php
        /**
         * Bandeau d'une carte que l'administrateur a masquée depuis qu'elle a
         * été connectée : elle ne subsiste que pour permettre la déconnexion.
         * Rendu DANS la carte (un bandeau en tête de page survivrait à la
         * disparition de la carte qu'il commente).
         */
        // Masquée par l'administrateur, ou retirée au rôle (Personnes → Rôles :
        // « Connecteurs — Utiliser ») : la cause dite est la vraie.
        $connectorRoleAllowed = !isset($connectorsAllowed) || $connectorsAllowed;
        $connectorHiddenNotice = static function (string $label) use ($connectorRoleAllowed): void { ?>
            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php if ($connectorRoleAllowed): ?>
                <?= htmlspecialchars($label) ?> <?= te('n\'est plus proposé par votre administrateur. Votre compte reste relié — vous pouvez le déconnecter ci-dessous — mais l\'option n\'apparaît plus dans MIA.') ?>
                <?php else: ?>
                <?= htmlspecialchars($label) ?> : <?= te('votre rôle ne vous permet plus de vous en servir. Votre compte reste relié — vous pouvez le déconnecter ci-dessous — mais l’option n’apparaît plus dans MIA.') ?>
                <?php endif; ?>
            </div>
        <?php }; ?>

        <?php
        /**
         * Connexion EXPIRÉE (jeton révoqué par le fournisseur) sur un connecteur
         * encore proposé : la carte invite à reconnecter — et propose aussi de
         * RETIRER la connexion morte. Sans cette issue, le rappel (pastille sur
         * l'avatar, menu du compte, ligne du menu « + ») resterait allumé pour
         * toujours chez qui ne veut plus relier ce service. La clé est celle du
         * registre : c'est aussi le segment de la route de déconnexion.
         */
        $removeExpired = static function (string $key): void { ?>
            <form method="POST" action="/settings/<?= htmlspecialchars($key) ?>/disconnect"
                  onsubmit='return confirm(<?= tj('Retirer cette connexion expirée ? Vous pourrez relier votre compte de nouveau plus tard.') ?>);'>
                <?= Csrf::field() ?>
                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
            </form>
        <?php }; ?>

        <?php if (empty($anyConnectionVisible)): ?>
            <div class="card" style="text-align:center;padding:32px 20px;color:var(--text-muted);font-size:14px">
                <?= te('Aucun service externe n\'est proposé par votre administrateur pour le moment.') ?>
            </div>
        <?php endif; ?>

        <!-- Carte : Google Drive -->
        <?php if (!empty($driveVisible)): ?>
        <div class="card" id="connector-drive">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/google-drive.svg" width="26" height="23" alt="<?= te('Google Drive') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Google Drive') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de rechercher et de {b}lire{/b} vos Google Docs, Sheets et autres documents pendant une conversation, et — si vous l\'autorisez — de {b}créer et modifier{/b} des Google Docs et Sheets à votre demande. Aucun fichier n\'est jamais supprimé.') ?>
                    </p>

                    <?php if (empty($driveFeatureEnabled)) { $connectorHiddenNotice('Google Drive'); } ?>

                    <?php if (!$oauthConfigured): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Connexion Google non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($driveToken !== null && ($driveToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($driveToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($driveToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($driveToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/drive/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre Google Drive ? Les assistants ne pourront plus lire vos documents.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <?php // La lecture s'affiche comme les autres : accordée ou non. Annoncée
                                  // en dur, elle masquait le seul état où le Drive paraît vide sans
                                  // qu'aucun message ne l'explique. ?>
                            <span class="badge <?= $driveCanRead ? 'badge-gray' : '' ?>" <?= $driveCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $driveCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $driveCanWriteDocs ? 'badge-gray' : '' ?>" <?= $driveCanWriteDocs ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Écriture Docs') ?> <?= $driveCanWriteDocs ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $driveCanWriteSheets ? 'badge-gray' : '' ?>" <?= $driveCanWriteSheets ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Écriture Sheets') ?> <?= $driveCanWriteSheets ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $driveCanOrganize ? 'badge-gray' : '' ?>" <?= $driveCanOrganize ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Renommer / déplacer') ?> <?= $driveCanOrganize ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $driveCanCopy ? 'badge-gray' : '' ?>" <?= $driveCanCopy ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Dupliquer') ?> <?= $driveCanCopy ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$driveCanRead || !$driveCanWriteDocs || !$driveCanWriteSheets || !$driveCanOrganize || !$driveCanCopy) && !empty($driveFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <?php // La lecture manquante n'est pas une capacité de plus : c'est la
                                      // panne qui fait dire « je n'ai pas accès » alors que le compte et
                                      // les partages sont bons. Elle a donc son propre texte, en tête. ?>
                                <?php if (!$driveCanRead): ?>
                                    <span><strong><?= te('Vos assistants ne voient aucun de vos fichiers') ?></strong> <?= te(': l\'autorisation « Voir et télécharger tous vos fichiers Google Drive » n\'a pas été accordée. Les recherches ne trouveront rien et vos documents paraîtront inaccessibles, même si le compte et les partages sont les bons. Pour y remédier :') ?></span>
                                <?php else: ?>
                                    <span><?= te('Votre connexion date d\'avant l\'arrivée de ces autorisations (ou vous ne les avez pas accordées). Pour que les assistants puissent créer et modifier vos Docs/Sheets, renommer, déplacer et dupliquer vos fichiers :') ?></span>
                                <?php endif; ?>
                                <a href="/settings/drive/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                                <span style="flex-basis:100%;font-size:12px"><?= te('Sur l\'écran de Google, laissez') ?> <strong><?= te('toutes les cases cochées') ?></strong> <?= te(': elles se décochent une à une, et une case oubliée retire silencieusement la capacité correspondante.') ?></span>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($driveFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté (jeton révoqué) :
                             // proposer une reconnexion mènerait à une option morte, mais le
                             // jeton doit rester retirable. ?>
                        <form method="POST" action="/settings/drive/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $driveRevoked = $driveToken !== null && ($driveToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($driveRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Google. Reconnectez votre Drive pour réactiver la lecture de vos documents.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/drive/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $driveRevoked ? te('Reconnecter mon Google Drive') : te('Connecter mon Google Drive') ?>
                            </a>
                            <?php if ($driveRevoked) { $removeExpired('drive'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Gmail -->
        <?php if (!empty($gmailVisible)): ?>
        <div class="card" id="connector-gmail">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/gmail.svg" width="26" height="20" alt="<?= te('Gmail') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Gmail') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}rechercher{/b} et de {b}lire{/b} vos emails pendant une conversation, et — à votre demande — de préparer des {b}emails{/b} que vous relisez dans une carte avant de les {b}enregistrer en brouillon{/b} ou de les {b}envoyer{/b}. {b}Rien ne part sans votre clic{/b} : l\'assistant n\'a aucun moyen d\'envoyer de lui-même.') ?>
                    </p>

                    <?php if (empty($gmailFeatureEnabled)) { $connectorHiddenNotice('Gmail'); } ?>

                    <?php if (!$oauthConfigured): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Connexion Google non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($gmailToken !== null && ($gmailToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($gmailToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($gmailToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($gmailToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/gmail/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre Gmail ? Les assistants ne pourront plus consulter vos emails.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $gmailCanRead ? 'badge-gray' : '' ?>" <?= $gmailCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $gmailCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $gmailCanDraft ? 'badge-gray' : '' ?>" <?= $gmailCanDraft ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Brouillons') ?> <?= $gmailCanDraft ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= !empty($gmailCanSend) ? 'badge-gray' : '' ?>" <?= !empty($gmailCanSend) ? '' : 'style="opacity:.45"' ?>
                                  title="<?= te('L\'envoi n\'a lieu que sur votre clic « Envoyer » dans la carte de relecture, après confirmation.') ?>">
                                <?= te('Envoi') ?> <?= !empty($gmailCanSend) ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= !empty($gmailCanSignature) ? 'badge-gray' : '' ?>" <?= !empty($gmailCanSignature) ? '' : 'style="opacity:.45"' ?>
                                  title="<?= te('Reprend automatiquement la signature de votre boîte Gmail dans les brouillons.') ?>">
                                <?= te('Signature') ?> <?= !empty($gmailCanSignature) ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$gmailCanRead || !$gmailCanDraft || empty($gmailCanSignature)) && !empty($gmailFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/gmail/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($gmailFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté (jeton révoqué) :
                             // proposer une reconnexion mènerait à une option morte, mais le
                             // jeton doit rester retirable. ?>
                        <form method="POST" action="/settings/gmail/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $gmailRevoked = $gmailToken !== null && ($gmailToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($gmailRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Google. Reconnectez votre Gmail pour réactiver la consultation de vos emails.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/gmail/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $gmailRevoked ? te('Reconnecter mon Gmail') : te('Connecter mon Gmail') ?>
                            </a>
                            <?php if ($gmailRevoked) { $removeExpired('gmail'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Google Agenda -->
        <?php if (!empty($calendarVisible)): ?>
        <div class="card" id="connector-calendar">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/google-calendar.svg" width="24" height="24" alt="<?= te('Google Agenda') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Google Agenda') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}consulter{/b} vos agendas et évènements pendant une conversation (« quelles réunions demain ? »), et — à votre demande — de {b}créer des évènements{/b}, chacun soumis à votre approbation. {b}Aucune invitation n\'est envoyée automatiquement{/b} aux participants, et aucun évènement n\'est jamais modifié ni supprimé.') ?>
                    </p>

                    <?php if (empty($calendarFeatureEnabled)) { $connectorHiddenNotice('Google Agenda'); } ?>

                    <?php if (!$oauthConfigured): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Connexion Google non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($calendarToken !== null && ($calendarToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($calendarToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($calendarToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($calendarToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/calendar/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre Google Agenda ? Les assistants ne pourront plus consulter vos évènements.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $calendarCanRead ? 'badge-gray' : '' ?>" <?= $calendarCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $calendarCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $calendarCanWrite ? 'badge-gray' : '' ?>" <?= $calendarCanWrite ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Création d\'évènements') ?> <?= $calendarCanWrite ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$calendarCanRead || !$calendarCanWrite) && !empty($calendarFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/calendar/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($calendarFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté (jeton révoqué) :
                             // proposer une reconnexion mènerait à une option morte, mais le
                             // jeton doit rester retirable. ?>
                        <form method="POST" action="/settings/calendar/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $calendarRevoked = $calendarToken !== null && ($calendarToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($calendarRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Google. Reconnectez votre agenda pour réactiver la consultation de vos évènements.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/calendar/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $calendarRevoked ? te('Reconnecter mon Google Agenda') : te('Connecter mon Google Agenda') ?>
                            </a>
                            <?php if ($calendarRevoked) { $removeExpired('calendar'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Google Tasks -->
        <?php if (!empty($tasksVisible)): ?>
        <div class="card" id="connector-tasks">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/google-tasks.svg" width="24" height="24" alt="<?= te('Google Tasks') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Google Tasks') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}consulter{/b} vos listes de tâches (« qu\'est-ce qu\'il me reste à faire ? »), et — à votre demande — d\'en {b}ajouter{/b}, d\'en {b}modifier{/b} et d\'en {b}supprimer{/b}. Chaque écriture est soumise à votre approbation. {b}La suppression est définitive{/b} : vous pouvez la désactiver ci-dessous, l\'assistant proposera alors de marquer la tâche comme terminée.') ?>
                    </p>

                    <?php if (empty($tasksFeatureEnabled)) { $connectorHiddenNotice('Google Tasks'); } ?>

                    <?php if (!$oauthConfigured): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Connexion Google non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($tasksToken !== null && ($tasksToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($tasksToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($tasksToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($tasksToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/tasks/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter vos tâches Google ? Les assistants ne pourront plus les consulter ni les modifier.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $tasksCanRead ? 'badge-gray' : '' ?>" <?= $tasksCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $tasksCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $tasksCanWrite ? 'badge-gray' : '' ?>" <?= $tasksCanWrite ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Ajout / modification / suppression') ?> <?= $tasksCanWrite ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$tasksCanRead || !$tasksCanWrite) && !empty($tasksFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/tasks/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($tasksFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté (jeton révoqué) :
                             // proposer une reconnexion mènerait à une option morte, mais le
                             // jeton doit rester retirable. ?>
                        <form method="POST" action="/settings/tasks/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $tasksRevoked = $tasksToken !== null && ($tasksToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($tasksRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Google. Reconnectez vos tâches pour réactiver leur consultation.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/tasks/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $tasksRevoked ? te('Reconnecter mes tâches Google') : te('Connecter mes tâches Google') ?>
                            </a>
                            <?php if ($tasksRevoked) { $removeExpired('tasks'); } ?>
                        </div>
                    <?php endif; ?>

                    <!-- Configuration côté utilisateur : préférences et informations
                         que l'assistant respectera. Toujours affichée (même non
                         connecté) : on peut préparer ses réglages avant de relier
                         son compte, et ils survivent à une déconnexion. -->
                    <?php $tp = $tasksPrefs ?? UserTasksService::defaultPrefs(); ?>
                    <form method="POST" action="/settings/tasks/prefs" data-autosave
                          style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
                        <?= Csrf::field() ?>
                        <h4 style="margin:0 0 4px;font-size:14px"><?= te('Vos préférences') ?></h4>
                        <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 14px">
                            <?= te('Ce que l\'assistant doit savoir sur votre façon de gérer vos tâches. Ces réglages s\'appliquent à toutes vos conversations.') ?>
                        </p>

                        <div class="form-group">
                            <label class="form-label" for="tasksDefaultList"><?= te('Liste par défaut') ?></label>
                            <?php if (!empty($tasksLists)): ?>
                                <select class="form-input" id="tasksDefaultList" name="liste_defaut_id"
                                        onchange="document.getElementById('tasksDefaultListName').value = this.options[this.selectedIndex].dataset.nom || '';">
                                    <option value="" data-nom=""><?= te('Liste principale de Google Tasks') ?></option>
                                    <?php foreach ($tasksLists as $tl): ?>
                                        <option value="<?= htmlspecialchars($tl['id']) ?>"
                                                data-nom="<?= htmlspecialchars($tl['nom']) ?>"
                                                <?= (string)$tp[UserTasksService::PREF_LIST_ID] === (string)$tl['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tl['nom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <!-- Libellé d'affichage : le service travaille sur l'id,
                                     ce champ ne sert qu'à nommer la liste dans les
                                     consignes envoyées au modèle. -->
                                <input type="hidden" id="tasksDefaultListName" name="liste_defaut_nom"
                                       value="<?= htmlspecialchars((string)$tp[UserTasksService::PREF_LIST_NAME]) ?>">
                            <?php else: ?>
                                <input type="hidden" name="liste_defaut_id" value="<?= htmlspecialchars((string)$tp[UserTasksService::PREF_LIST_ID]) ?>">
                                <input type="hidden" name="liste_defaut_nom" value="<?= htmlspecialchars((string)$tp[UserTasksService::PREF_LIST_NAME]) ?>">
                                <p style="font-size:12.5px;color:var(--text-muted);margin:0">
                                    <?php if (!empty($tasksListsError)): ?>
                                        <?= te('Vos listes n\'ont pas pu être chargées ({motif}). Votre choix actuel est conservé.', ['motif' => $tasksListsError]) ?>
                                    <?php else: ?>
                                        <?= te('Connectez vos tâches pour choisir la liste dans laquelle l\'assistant travaille par défaut.') ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="tasksHorizon"><?= te('Fenêtre d\'échéance par défaut') ?></label>
                            <select class="form-input" id="tasksHorizon" name="horizon_jours">
                                <?php foreach ([0 => t('Aucune limite (toutes les tâches)'), 7 => t('Les 7 prochains jours'), 14 => t('Les 14 prochains jours'), 30 => t('Les 30 prochains jours'), 90 => t('Les 90 prochains jours')] as $val => $lib): ?>
                                    <option value="<?= (int)$val ?>" <?= (int)$tp[UserTasksService::PREF_HORIZON] === (int)$val ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lib) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0">
                                <?= te('Attention : dès qu\'une fenêtre est appliquée, Google exclut les tâches') ?>
                                <strong><?= te('sans échéance') ?></strong><?= te('. « Aucune limite » les fait toutes remonter.') ?>
                            </p>
                        </div>

                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;margin-bottom:12px;cursor:pointer">
                            <input type="checkbox" name="masquer_terminees" value="1" style="margin-top:2px"
                                   <?= !empty($tp[UserTasksService::PREF_HIDE_DONE]) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= te('Masquer les tâches déjà terminées') ?></strong><br>
                                <span style="color:var(--text-muted)"><?= te('L\'assistant ne listera que ce qu\'il reste à faire, sauf demande explicite.') ?></span>
                            </span>
                        </label>

                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;margin-bottom:14px;cursor:pointer">
                            <input type="checkbox" name="autoriser_suppression" value="1" style="margin-top:2px"
                                   <?= !empty($tp[UserTasksService::PREF_ALLOW_DELETE]) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= te('Autoriser la suppression de tâches') ?></strong><br>
                                <span style="color:var(--text-muted)">
                                    <?= te('Décoché, l\'assistant n\'a plus aucun moyen de supprimer : il proposera de marquer la tâche comme terminée. Coché, chaque suppression reste soumise à votre approbation, mais elle est') ?> <strong><?= te('irréversible') ?></strong>.
                                </span>
                            </span>
                        </label>

                        <?php $tasksRules = UserTasksService::normalizeRules($tp[UserTasksService::PREF_RULES] ?? []); ?>
                        <div class="form-group">
                            <label class="form-label"><?= te('Vos règles d\'organisation') ?></label>
                            <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 10px">
                                <?= te('Une règle par ligne. L\'assistant les respecte à chaque fois qu\'il touche à vos tâches — et il peut vous proposer d\'en retenir de nouvelles en conversation (« je retiens que les relances vont dans Travail ? »).') ?>
                            </p>

                            <?php if ($tasksRules !== []): ?>
                                <ul style="list-style:none;padding:0;margin:0 0 10px;display:flex;flex-direction:column;gap:8px">
                                    <?php foreach ($tasksRules as $r): ?>
                                        <li style="display:flex;align-items:center;gap:8px">
                                            <input type="text" class="form-input" style="flex:1"
                                                   name="regles[<?= htmlspecialchars($r['id']) ?>]"
                                                   maxlength="<?= UserTasksService::MAX_RULE_LEN ?>"
                                                   value="<?= htmlspecialchars($r['texte']) ?>">
                                            <?php if ($r['source'] === UserTasksService::RULE_SOURCE_CHAT): ?>
                                                <span class="badge badge-gray" title="<?= te('Retenue depuis une conversation') ?>"><?= te('Chat') ?></span>
                                            <?php endif; ?>
                                            <!-- Vider le champ SUPPRIME la règle (cf. normalizeRules) : le
                                                 formulaire entier est reposté, pas de route de suppression à
                                                 part. L'évènement `input` est dispatché explicitement — une
                                                 affectation par script n'en émet aucun, et l'auto-save ne
                                                 verrait donc pas le geste. -->
                                            <button type="button" class="btn-secondary" style="font-size:13px;padding:6px 10px"
                                                    title="<?= te('Supprimer cette règle') ?>"
                                                    onclick="const i=this.parentElement.querySelector('input');i.value='';i.dispatchEvent(new Event('input',{bubbles:true}));this.parentElement.remove();">×</button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <input type="text" class="form-input" name="nouvelle_regle"
                                   maxlength="<?= UserTasksService::MAX_RULE_LEN ?>"
                                   data-autosave-reset
                                   placeholder="<?= te('Ajouter une règle — ex. : les relances client vont dans la liste Travail') ?>">
                            <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0">
                                <?= te('{max} règles maximum, {len} caractères chacune. Vider une règle la supprime. Ces règles guident les formulations et les choix de liste de l\'assistant — elles n\'élargissent jamais ses droits.', ['max' => UserTasksService::MAX_RULES, 'len' => UserTasksService::MAX_RULE_LEN]) ?>
                            </p>
                        </div>

                        <div style="display:flex;justify-content:flex-end">
                            <?php require ROOT . '/templates/partials/autosave-hint.php'; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Outlook (famille Microsoft, connecteur de chat) -->
        <?php if (!empty($outlookVisible)): ?>
        <div class="card" id="connector-outlook">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/outlook.svg" width="26" height="26" alt="<?= te('Outlook') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Outlook') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}rechercher{/b} et de {b}lire{/b} vos emails pendant une conversation, et — à votre demande — de préparer des {b}emails{/b} que vous relisez dans une carte avant de les {b}enregistrer en brouillon{/b} ou de les {b}envoyer{/b}. {b}Rien ne part sans votre clic{/b} : l\'assistant n\'a aucun moyen d\'envoyer de lui-même.') ?>
                    </p>

                    <?php if (empty($outlookFeatureEnabled)) { $connectorHiddenNotice('Outlook'); } ?>

                    <?php if (!MicrosoftOutlookOAuth::isConfigured()): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Application Microsoft non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($outlookToken !== null && ($outlookToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($outlookToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($outlookToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($outlookToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/outlook/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre Outlook ? Les assistants ne pourront plus consulter vos emails.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $outlookCanRead ? 'badge-gray' : '' ?>" <?= $outlookCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $outlookCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $outlookCanDraft ? 'badge-gray' : '' ?>" <?= $outlookCanDraft ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Brouillons') ?> <?= $outlookCanDraft ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= !empty($outlookCanSend) ? 'badge-gray' : '' ?>" <?= !empty($outlookCanSend) ? '' : 'style="opacity:.45"' ?>
                                  title="<?= te('L\'envoi n\'a lieu que sur votre clic « Envoyer » dans la carte de relecture, après confirmation.') ?>">
                                <?= te('Envoi') ?> <?= !empty($outlookCanSend) ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$outlookCanRead || !$outlookCanDraft || empty($outlookCanSend)) && !empty($outlookFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement — un administrateur de votre annuaire Microsoft doit parfois la valider. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/outlook/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($outlookFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté : proposer une
                             // reconnexion mènerait à une option morte, mais le jeton doit
                             // rester retirable. ?>
                        <form method="POST" action="/settings/outlook/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $outlookRevoked = $outlookToken !== null && ($outlookToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($outlookRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Microsoft. Reconnectez votre Outlook pour réactiver la consultation de vos emails.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/outlook/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $outlookRevoked ? te('Reconnecter mon Outlook') : te('Connecter mon Outlook') ?>
                            </a>
                            <?php if ($outlookRevoked) { $removeExpired('outlook'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Calendrier Outlook (famille Microsoft, connecteur de chat) -->
        <?php if (!empty($msCalendarVisible)): ?>
        <div class="card" id="connector-mscalendar">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/outlook-calendar.svg" width="26" height="26" alt="<?= te('Calendrier Outlook') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Calendrier Outlook') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}consulter vos évènements{/b} pendant une conversation (« suis-je libre jeudi ? », « qu\'ai-je la semaine prochaine ? ») et — à votre demande — d\'en {b}créer{/b} après votre approbation. Aucun évènement n\'est jamais modifié ni supprimé, et {b}aucune invitation n\'est envoyée{/b} : les évènements sont créés sans participant, vous les ajoutez vous-même dans Outlook.') ?>
                    </p>

                    <?php if (empty($msCalendarFeatureEnabled)) { $connectorHiddenNotice('Calendrier Outlook'); } ?>

                    <?php if (!MicrosoftCalendarOAuth::isConfigured()): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Application Microsoft non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($msCalendarToken !== null && ($msCalendarToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($msCalendarToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($msCalendarToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($msCalendarToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/mscalendar/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre calendrier Outlook ? Les assistants ne pourront plus consulter vos évènements.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $msCalendarCanRead ? 'badge-gray' : '' ?>" <?= $msCalendarCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $msCalendarCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $msCalendarCanWrite ? 'badge-gray' : '' ?>" <?= $msCalendarCanWrite ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Création d\'évènements') ?> <?= $msCalendarCanWrite ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$msCalendarCanRead || !$msCalendarCanWrite) && !empty($msCalendarFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement — un administrateur de votre annuaire Microsoft doit parfois la valider. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/mscalendar/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($msCalendarFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté : proposer une
                             // reconnexion mènerait à une option morte, mais le jeton doit
                             // rester retirable. ?>
                        <form method="POST" action="/settings/mscalendar/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $msCalendarRevoked = $msCalendarToken !== null && ($msCalendarToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($msCalendarRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Microsoft. Reconnectez votre calendrier Outlook pour réactiver la consultation de vos évènements.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/mscalendar/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $msCalendarRevoked ? te('Reconnecter mon calendrier Outlook') : te('Connecter mon calendrier Outlook') ?>
                            </a>
                            <?php if ($msCalendarRevoked) { $removeExpired('mscalendar'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Microsoft To Do (famille Microsoft, connecteur de chat) -->
        <?php if (!empty($msTodoVisible)): ?>
        <div class="card" id="connector-mstodo">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/microsoft-todo.svg" width="26" height="26" alt="<?= te('Microsoft To Do') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Microsoft To Do') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}consulter vos tâches{/b} pendant une conversation (« qu’ai-je à faire cette semaine ? ») et — à votre demande — d’en {b}ajouter ou de les modifier{/b} (échéance, rappel, la marquer terminée) après votre approbation. Aucune tâche n’est jamais supprimée.') ?>
                    </p>

                    <?php if (empty($msTodoFeatureEnabled)) { $connectorHiddenNotice('Microsoft To Do'); } ?>

                    <?php if (!MicrosoftTodoOAuth::isConfigured()): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Application Microsoft non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($msTodoToken !== null && ($msTodoToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($msTodoToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($msTodoToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($msTodoToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/mstodo/disconnect" style="margin-left:auto"
                                  onsubmit='return confirm(<?= tj('Déconnecter Microsoft To Do ? Les assistants ne pourront plus consulter vos tâches.') ?>);'>
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $msTodoCanRead ? 'badge-gray' : '' ?>" <?= $msTodoCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $msTodoCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $msTodoCanWrite ? 'badge-gray' : '' ?>" <?= $msTodoCanWrite ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Création et modification de tâches') ?> <?= $msTodoCanWrite ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$msTodoCanRead || !$msTodoCanWrite) && !empty($msTodoFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement — un administrateur de votre annuaire Microsoft doit parfois la valider. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/mstodo/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($msTodoFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté : le jeton doit
                             // rester retirable, sans proposer de reconnexion. ?>
                        <form method="POST" action="/settings/mstodo/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $msTodoRevoked = $msTodoToken !== null && ($msTodoToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($msTodoRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Microsoft. Reconnectez Microsoft To Do pour réactiver la consultation de vos tâches.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/mstodo/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $msTodoRevoked ? te('Reconnecter Microsoft To Do') : te('Connecter Microsoft To Do') ?>
                            </a>
                            <?php if ($msTodoRevoked) { $removeExpired('mstodo'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : Microsoft Teams (famille Microsoft, connecteur de chat) -->
        <?php if (!empty($teamsVisible)): ?>
        <div class="card" id="connector-teams">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/microsoft-teams.svg" width="26" height="26" alt="<?= te('Microsoft Teams') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Microsoft Teams') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}rechercher et lire vos conversations et les canaux de vos équipes{/b} pendant une conversation (« qu’a dit Marie sur le budget ? ») et — à votre demande — de {b}publier un message en votre nom{/b} après votre approbation. Un message publié est aussitôt visible ; aucun message n’est jamais modifié ni supprimé.') ?>
                    </p>

                    <?php if (empty($teamsFeatureEnabled)) { $connectorHiddenNotice('Microsoft Teams'); } ?>

                    <?php if (!MicrosoftTeamsOAuth::isConfigured()): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Application Microsoft non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($teamsToken !== null && ($teamsToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($teamsToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($teamsToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($teamsToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/teams/disconnect" style="margin-left:auto"
                                  onsubmit='return confirm(<?= tj('Déconnecter Microsoft Teams ? Les assistants ne pourront plus lire vos conversations ni vos canaux.') ?>);'>
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $teamsCanReadChats ? 'badge-gray' : '' ?>" <?= $teamsCanReadChats ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Conversations') ?> <?= $teamsCanReadChats ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $teamsCanReadChannels ? 'badge-gray' : '' ?>" <?= $teamsCanReadChannels ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Canaux') ?> <?= $teamsCanReadChannels ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $teamsCanSend ? 'badge-gray' : '' ?>" <?= $teamsCanSend ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Publication de messages') ?> <?= $teamsCanSend ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$teamsCanReadChats || !$teamsCanReadChannels || !$teamsCanSend) && !empty($teamsFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée — la lecture des canaux exige le consentement d\'un administrateur de votre annuaire Microsoft. Une fois ce consentement donné :') ?></span>
                                <a href="/settings/teams/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($teamsFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté : le jeton doit
                             // rester retirable, sans proposer de reconnexion. ?>
                        <form method="POST" action="/settings/teams/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $teamsRevoked = $teamsToken !== null && ($teamsToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($teamsRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Microsoft. Reconnectez Microsoft Teams pour réactiver la lecture de vos conversations.') ?>
                            </div>
                        <?php endif; ?>
                        <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px">
                            <?= te('Un administrateur de votre annuaire Microsoft doit d’abord autoriser MIA pour toute l’organisation : sans cela, Microsoft affiche « Approbation de l’administrateur requise ».') ?>
                        </p>
                        <div class="connector-actions">
                            <a href="/settings/teams/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $teamsRevoked ? te('Reconnecter Microsoft Teams') : te('Connecter Microsoft Teams') ?>
                            </a>
                            <?php if ($teamsRevoked) { $removeExpired('teams'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php /* Carte : Slack (troisième famille — application Slack dédiée). */ ?>
        <?php if (!empty($slackVisible)): ?>
        <div class="card" id="connector-slack">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/slack.svg" width="26" height="26" alt="<?= te('Slack') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('Slack') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= teb('Permet aux assistants de {b}rechercher et lire{/b} vos conversations Slack pendant un échange (« qu\'a-t-on dit du budget ? », « résume #projet-x »), et — à votre demande — de {b}publier un message en votre nom{/b} après votre approbation. MIA ne voit que les conversations dont vous êtes déjà membre. Un message publié est {b}immédiatement visible{/b} par le canal : il ne peut plus être ni modifié ni supprimé par MIA.') ?>
                    </p>

                    <?php if (empty($slackFeatureEnabled)) { $connectorHiddenNotice('Slack'); } ?>

                    <?php if (!SlackMessagesOAuth::isConfigured()): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Application Slack non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($slackToken !== null && ($slackToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($slackToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($slackToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($slackToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/slack/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre Slack ? Les assistants ne pourront plus lire vos conversations ni publier en votre nom.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge <?= $slackCanSearch ? 'badge-gray' : '' ?>" <?= $slackCanSearch ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Recherche') ?> <?= $slackCanSearch ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $slackCanRead ? 'badge-gray' : '' ?>" <?= $slackCanRead ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Lecture') ?> <?= $slackCanRead ? '✓' : '—' ?>
                            </span>
                            <span class="badge <?= $slackCanPost ? 'badge-gray' : '' ?>" <?= $slackCanPost ? '' : 'style="opacity:.45"' ?>>
                                <?= te('Publication') ?> <?= $slackCanPost ? '✓' : '—' ?>
                            </span>
                        </div>
                        <?php if ((!$slackCanSearch || !$slackCanRead || !$slackCanPost) && !empty($slackFeatureEnabled)): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted);flex-wrap:wrap">
                                <span><?= te('Une autorisation n\'a pas été accordée lors du consentement — sur certains espaces de travail, un administrateur Slack doit approuver l\'application. Pour un fonctionnement complet :') ?></span>
                                <a href="/settings/slack/connect" class="btn-secondary" style="font-size:12px;text-decoration:none"><?= te('Reconnecter et autoriser') ?></a>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($slackFeatureEnabled)): ?>
                        <?php // Masqué par l'administrateur et non connecté : proposer une
                             // reconnexion mènerait à une option morte, mais le jeton doit
                             // rester retirable. ?>
                        <form method="POST" action="/settings/slack/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $slackRevoked = $slackToken !== null && ($slackToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($slackRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Slack. Reconnectez votre Slack pour réactiver la lecture de vos conversations.') ?>
                            </div>
                        <?php endif; ?>
                        <div class="connector-actions">
                            <a href="/settings/slack/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $slackRevoked ? te('Reconnecter mon Slack') : te('Connecter mon Slack') ?>
                            </a>
                            <?php if ($slackRevoked) { $removeExpired('slack'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Carte : OneDrive (source de bases de connaissances, pas connecteur
             de chat) — masquée pour qui ne gère aucune base : pas d'option morte. -->
        <?php if (!empty($oneDriveVisible)): ?>
        <div class="card" id="connector-onedrive">
            <div style="display:flex;align-items:flex-start;gap:14px">
                <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center">
                    <img src="/assets/img/onedrive.svg" width="26" height="26" alt="<?= te('OneDrive') ?>">
                </div>
                <div style="flex:1">
                    <h3 style="margin:0 0 4px;font-size:15px"><?= te('OneDrive') ?></h3>
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
                        <?= te('Permet de') ?> <strong><?= te('parcourir vos dossiers') ?></strong> <?= te('OneDrive (et ceux partagés avec vous) pour en faire la source d\'une') ?> <strong><?= te('base de connaissances') ?></strong> <?= te(': les documents du dossier choisi sont indexés et interrogés par vos assistants.') ?>
                        <strong><?= te('Lecture seule') ?></strong> <?= te('— rien n\'est jamais créé, modifié ni supprimé chez Microsoft.') ?>
                    </p>

                    <?php if (empty($oneDriveFeatureEnabled) && $oneDriveToken !== null) { $connectorHiddenNotice('OneDrive'); } ?>

                    <?php if (empty($oneDriveFeatureEnabled) && $oneDriveToken === null): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?= te('Connexion Microsoft non configurée sur cette instance. Contactez votre administrateur.') ?>
                        </div>
                    <?php elseif ($oneDriveToken !== null && ($oneDriveToken['status'] ?? '') === 'ok'): ?>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span class="badge badge-accent"><?= te('Connecté') ?></span>
                            <?php if (!empty($oneDriveToken['google_email'])): ?>
                                <span style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($oneDriveToken['google_email']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                <?= te('depuis le') ?> <?= date('d/m/Y', strtotime((string)($oneDriveToken['created_at'] ?? 'now'))) ?>
                            </span>
                            <form method="POST" action="/settings/onedrive/disconnect" style="margin-left:auto"
                                  onsubmit="return confirm('Déconnecter votre OneDrive ? Les bases de connaissances qui lisent un dossier avec cette connexion cesseront de se synchroniser.');">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Déconnecter') ?></button>
                            </form>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px">
                            <span class="badge badge-gray"><?= te('Lecture') ?></span>
                            <span class="badge badge-gray"><?= te('Navigation dans les dossiers') ?></span>
                        </div>
                        <?php if (ConnectorRegistry::oneDriveUsefulFor((string)($user['role'] ?? ''))): ?>
                            <!-- Connecter ne sert à rien tant qu'on ne sait pas
                                 où la connexion s'utilise : la suite du parcours
                                 est donc rappelée ici, pas seulement dans le guide. -->
                            <div style="margin-top:10px;padding:10px 12px;background:var(--bg-surface);border-radius:8px;font-size:13px;color:var(--text-muted)">
                                <strong style="color:var(--text)"><?= te('Et maintenant ?') ?></strong>
                                <?= te('Ouvrez') ?> <a href="/admin/knowledge-bases"><?= te('Bases de connaissances') ?></a><?= te(', créez ou modifiez une base, section') ?> <strong><?= te('Alimentation') ?></strong> <?= te('→ carte') ?> <strong><?= te('Dossier OneDrive') ?></strong> →
                                <strong><?= te('Parcourir…') ?></strong> <?= te('pour choisir le dossier à indexer.') ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif (empty($oneDriveFeatureEnabled)): ?>
                        <?php // Masqué (ou Azure retiré) et non connecté : jeton révoqué
                             // encore en base — retirable, mais pas de reconnexion. ?>
                        <form method="POST" action="/settings/onedrive/disconnect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn-secondary" style="font-size:13px"><?= te('Retirer cette connexion') ?></button>
                        </form>
                    <?php else: ?>
                        <?php $oneDriveRevoked = $oneDriveToken !== null && ($oneDriveToken['status'] ?? '') === 'revoked'; ?>
                        <?php if ($oneDriveRevoked): ?>
                            <div class="alert alert-error" style="margin-bottom:12px;font-size:13px">
                                <?= te('La connexion a expiré ou a été révoquée côté Microsoft. Reconnectez votre OneDrive pour relancer la synchronisation des bases qui en dépendent.') ?>
                            </div>
                        <?php endif; ?>
                        <ol style="font-size:13px;color:var(--text-muted);margin:0 0 14px;padding-left:20px;line-height:1.6">
                            <li><strong><?= te('Connecter mon OneDrive') ?></strong> <?= te('ci-dessous : Microsoft demande votre accord pour la') ?> <strong><?= te('lecture') ?></strong> <?= te('de vos fichiers.') ?></li>
                            <li><?= te('Ouvrez') ?> <a href="/admin/knowledge-bases"><?= te('Bases de connaissances') ?></a> <?= te('et créez (ou modifiez) une base.') ?></li>
                            <li><?= te('Section') ?> <strong><?= te('Alimentation') ?></strong> <?= te('→ carte') ?> <strong><?= te('Dossier OneDrive') ?></strong> → <strong><?= te('Parcourir…') ?></strong> <?= te(': choisissez le dossier, MIA l\'indexe et le suit automatiquement.') ?></li>
                        </ol>
                        <div class="connector-actions">
                            <a href="/settings/onedrive/connect" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:13px">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?= $oneDriveRevoked ? te('Reconnecter mon OneDrive') : te('Connecter mon OneDrive') ?>
                            </a>
                            <?php if ($oneDriveRevoked) { $removeExpired('onedrive'); } ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /max-width -->
</div><!-- /admin-content -->

</body>
</html>
