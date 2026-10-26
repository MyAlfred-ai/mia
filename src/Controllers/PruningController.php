<?php

declare(strict_types=1);

/**
 * Page « Élagage » (Maintenance → Données → Élagage), admins seuls.
 * Le travail réel est dans PruningService — ici : gardes, confirmation, rapport.
 *
 * Après chaque action : redirection vers la page, qui RECALCULE tout. Les
 * chiffres affichés ne peuvent donc pas être périmés, et il n'y a aucun rendu
 * côté client à maintenir en parallèle du serveur.
 */
class PruningController
{
    /** Mot à saisir pour confirmer une action destructive (doublé côté client). */
    public const MOT_CONFIRMATION = 'ELAGUER';

    /** GET /admin/pruning */
    public function index(): void
    {
        AuthMiddleware::requireAdmin();

        $forcer = isset($_GET['recalculer']);

        // Le calcul lit tous les LONGTEXT de messages : la session est fermée
        // d'abord, sinon toute autre requête du même admin attendrait derrière.
        session_write_close();
        $stats = PruningService::statistiques($forcer);
        Session::start();

        $joursConversations = PruningService::joursConversations();
        $joursCreations     = PruningService::joursCreations();
        $success            = Session::getFlash('pruning_success');
        $error              = Session::getFlash('pruning_error');

        require ROOT . '/templates/admin/pruning.php';
    }

    /**
     * POST /admin/pruning/settings — nombre de jours (auto-enregistré).
     * Seul écran de la page à passer par autosave : les trois actions
     * destructives gardent un bouton et une confirmation explicites.
     */
    public function saveSettings(): void
    {
        AuthMiddleware::requireAdmin();

        // isset() et non ?? : un champ absent du POST ne doit rien écraser
        // (même convention que l'intervalle de synchronisation des bases).
        if (isset($_POST['conversations_days'])) {
            Settings::set(
                PruningService::CLE_JOURS_CONV,
                (string)PruningService::joursValides($_POST['conversations_days'])
            );
        }
        if (isset($_POST['creations_days'])) {
            Settings::set(
                PruningService::CLE_JOURS_CREATION,
                (string)PruningService::joursValides($_POST['creations_days'])
            );
        }

        // Les seuils ont changé : les « éligibles » affichés ne valent plus rien.
        PruningService::invaliderCache();

        AjaxResponse::finish(
            AjaxResponse::wanted(),
            'pruning_success',
            t("Réglages d'élagage enregistrés."),
            '/admin/pruning'
        );
    }

    /** POST /admin/pruning/conversations */
    public function pruneConversations(): void
    {
        AuthMiddleware::requireAdmin();
        if (!$this->confirme()) {
            return;
        }

        $jours   = PruningService::joursConversations();
        $rapport = PruningService::elaguerConversations($jours);

        if ($rapport['verrou'] === false) {
            $this->repondre(false, t('Un élagage est déjà en cours. Réessayez dans un instant.'));
        }

        $this->repondre(true, $this->messageConversations($rapport));
    }

    /** POST /admin/pruning/creations */
    public function pruneCreations(): void
    {
        AuthMiddleware::requireAdmin();
        if (!$this->confirme()) {
            return;
        }

        $jours   = PruningService::joursCreations();
        $rapport = PruningService::elaguerCreations($jours);

        if ($rapport['verrou'] === false) {
            $this->repondre(false, t('Un élagage est déjà en cours. Réessayez dans un instant.'));
        }

        $this->repondre(true, $this->messageCreations($rapport));
    }

    /** POST /admin/pruning/orphans */
    public function sweepOrphans(): void
    {
        AuthMiddleware::requireAdmin();
        if (!$this->confirme()) {
            return;
        }

        $rapport = PruningService::balayerOrphelinsSeul();

        if ($rapport['verrou'] === false) {
            $this->repondre(false, t('Un élagage est déjà en cours. Réessayez dans un instant.'));
        }

        $this->repondre(true, $this->messageBalayage($rapport, true));
    }

    // ------------------------------------------------------------- Plomberie

    /**
     * Confirmation doublée côté serveur : la garde `onsubmit` du gabarit ne
     * protège que d'un clic distrait, jamais d'un POST forgé.
     * Termine la requête (redirection) et retourne false si le mot manque.
     */
    private function confirme(): bool
    {
        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) === self::MOT_CONFIRMATION) {
            return true;
        }
        $this->repondre(false, t('Élagage annulé : tapez {mot} dans le champ de confirmation.', ['mot' => self::MOT_CONFIRMATION]));
        return false;
    }

    /**
     * Flash + redirection. Ne revient jamais.
     *
     * `Session::start()` D'ABORD, et ce n'est pas décoratif : l'élagage ferme la
     * session avant son travail long (sinon toute autre requête du même admin
     * attendrait derrière). Écrire le flash sans rouvrir la session le poserait
     * dans un $_SESSION en mémoire que plus personne ne persiste — l'admin
     * verrait la page revenir SANS le moindre rapport, comme si rien ne s'était
     * passé. Même précaution que BackupController::import().
     */
    private function repondre(bool $ok, string $message): never
    {
        Session::start();
        Session::flash($ok ? 'pruning_success' : 'pruning_error', $message);
        header('Location: /admin/pruning');
        exit;
    }

    /*
     * Rapports affichés après un élagage, dans la langue de l'écran. Chaque
     * compte passe par I18n::plural() : le pluriel diffère entre le français
     * (> 1) et l'anglais (!= 1).
     */
    private function messageConversations(array $r): string
    {
        $n   = (int)$r['supprimees'];
        $msg = t('Élagage à {jours} jours : {details}.', [
            'jours'   => (int)$r['jours'],
            'details' => I18n::plural($n, '{n} conversation supprimée', '{n} conversations supprimées', ['n' => Format::credits($n)])
                . ' (' . Format::sizeGoMo((int)$r['octets']) . ')',
        ]);

        if (!empty($r['budget_atteint'])) {
            $msg .= ' ' . t('Budget de temps atteint : il en reste {n}. Relancez pour continuer.', ['n' => Format::credits((int)$r['restantes'])]);
        }

        return $msg . ' ' . $this->messageBalayage($r['balayage'], false)
            . ' ' . t("L'historique de consommation et les quotas ne sont pas affectés.");
    }

    private function messageCreations(array $r): string
    {
        $n       = (int)$r['neutralisees'];
        $details = [I18n::plural($n, '{n} création neutralisée', '{n} créations neutralisées', ['n' => Format::credits($n)])];
        if ((int)$r['images'] > 0) {
            $details[] = I18n::plural((int)$r['images'], '{n} message dont seules les images ont été retirées', '{n} messages dont seules les images ont été retirées', ['n' => Format::credits((int)$r['images'])]);
        }
        if ((int)$r['partages'] > 0) {
            $details[] = I18n::plural((int)$r['partages'], '{n} lien de partage révoqué', '{n} liens de partage révoqués', ['n' => Format::credits((int)$r['partages'])]);
        }
        $msg = t('Élagage à {jours} jours : {details}.', ['jours' => (int)$r['jours'], 'details' => implode(', ', $details)]);

        if ((int)$r['ignorees'] > 0) {
            $msg .= ' ' . I18n::plural(
                (int)$r['ignorees'],
                '{n} message ignoré (contenu inhabituellement volumineux, laissé intact).',
                '{n} messages ignorés (contenu inhabituellement volumineux, laissé intact).',
                ['n' => Format::credits((int)$r['ignorees'])]
            );
        }
        if (!empty($r['budget_atteint'])) {
            $msg .= ' ' . t('Budget de temps atteint : relancez pour continuer.');
        }

        return $msg . ' ' . $this->messageBalayage($r['balayage'], false);
    }

    /**
     * Les échecs de suppression sont TOUJOURS dits : `unlink` échoue en silence
     * quand le répertoire appartient à root, et un rapport qui annoncerait
     * « 0 fichier » sans expliquer serait un mensonge par omission.
     */
    private function messageBalayage(array $b, bool $seul): string
    {
        $vars = [
            'fichiers' => I18n::plural((int)$b['supprimes'], '{n} fichier orphelin supprimé du disque', '{n} fichiers orphelins supprimés du disque', ['n' => Format::credits((int)$b['supprimes'])]),
            'taille'   => Format::sizeGoMo((int)$b['octets']),
        ];
        $msg = $seul ? t('Balayage : {fichiers} ({taille}).', $vars) : t('Fichiers : {fichiers} ({taille}).', $vars);

        if ((int)$b['echecs'] > 0) {
            $msg .= ' ' . I18n::plural(
                (int)$b['echecs'],
                '⚠ {n} suppression refusée par le système de fichiers — vérifiez les droits d\'écriture des répertoires de médias.',
                '⚠ {n} suppressions refusées par le système de fichiers — vérifiez les droits d\'écriture des répertoires de médias.'
            );
        }
        if ($seul && (int)$b['jeunes'] > 0) {
            $msg .= ' ' . I18n::plural(
                (int)$b['jeunes'],
                '{n} fichier de moins de 24 h conservé (une génération peut être en cours).',
                '{n} fichiers de moins de 24 h conservés (une génération peut être en cours).'
            );
        }

        return $msg;
    }
}
