<?php

declare(strict_types=1);

/**
 * Premier démarrage : création du compte administrateur initial.
 *
 * Cet écran N'ÉCRIT PLUS le fichier .env, et ne demande donc plus ni l'adresse
 * de l'application, ni les identifiants MySQL, ni Google OAuth. Trois raisons,
 * toutes constatées en production :
 *
 *  1. Sous Docker, il écrivait /var/www/html/.env À L'INTÉRIEUR du conteneur :
 *     fichier exclu de l'image par .dockerignore, monté nulle part, perdu à la
 *     reconstruction suivante.
 *  2. Même bien écrit, ce .env n'aurait pas été relu : `docker compose` fournit
 *     déjà les variables dans l'environnement du process, qui prime sur le
 *     fichier (cf. config/app.php). L'application repartait donc en boucle vers
 *     /setup, indéfiniment.
 *  3. `reloadEnv()` ne pouvait pas redéfinir DB_HOST, DB_USER… : `config/app.php`
 *     les avait déjà fixées avec leurs valeurs par défaut, et une constante PHP
 *     ne se redéfinit pas. Le compte administrateur était donc créé sur une
 *     AUTRE base que celle saisie dans le formulaire.
 *
 * La configuration vient désormais du .env, produit par `./install.sh` ou écrit
 * à la main ; son absence est signalée par sa propre page d'erreur (Bootstrap).
 * Ici, on ne fait plus qu'une chose, et bien : créer le premier administrateur.
 */
class SetupController
{
    /**
     * Vrai tant qu'aucun compte n'existe.
     *
     * Même prédicat que la règle « premier utilisateur Google = administrateur »
     * de User::createFromSso() : les deux chemins d'amorçage ne peuvent plus
     * diverger.
     */
    public static function isNeeded(): bool
    {
        return Bootstrap::needsFirstAdmin();
    }

    public function index(): void
    {
        $erreurs = [];
        $saisie  = ['name' => '', 'email' => ''];
        require ROOT . '/templates/setup/index.php';
    }

    public function save(): void
    {
        // Garde-fou : la table peut s'être remplie entre l'affichage et l'envoi
        // (installation à deux mains, ou double soumission).
        if (!Bootstrap::needsFirstAdmin()) {
            header('Location: /auth/login');
            exit;
        }

        $nom          = trim((string) ($_POST['admin_name'] ?? ''));
        $email        = trim((string) ($_POST['admin_email'] ?? ''));
        $motDePasse   = (string) ($_POST['admin_password'] ?? '');
        $confirmation = (string) ($_POST['admin_password_confirm'] ?? '');

        $erreurs = [];
        if ($nom === '') {
            $erreurs[] = 'Le nom est requis.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "L'adresse e-mail est invalide.";
        }
        if (strlen($motDePasse) < 8) {
            $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ($motDePasse !== $confirmation) {
            $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
        }

        if ($erreurs !== []) {
            $saisie = ['name' => $nom, 'email' => $email];
            require ROOT . '/templates/setup/index.php';
            return;
        }

        try {
            User::createWithPassword($nom, $email, $motDePasse, 'admin', 1);
        } catch (\Throwable $e) {
            ErrorHandler::journaliser($e);
            $erreurs[] = "La création du compte a échoué : " . $e->getMessage();
            $saisie    = ['name' => $nom, 'email' => $email];
            require ROOT . '/templates/setup/index.php';
            return;
        }

        // Laissez-passer pour l'écran suivant : il est atteint alors que
        // l'utilisateur n'est pas encore connecté, et il révèle l'état de
        // configuration (clés API présentes ou non). Sans ce marqueur, la page
        // serait consultable par n'importe qui, à tout moment.
        Session::set('setup_termine', true);

        header('Location: /setup/suivant');
        exit;
    }

    /**
     * Écran de fin d'installation : ce qui reste à configurer, avec l'état réel
     * de chaque point. L'ancien parcours renvoyait sèchement vers /auth/login,
     * sans dire qu'il fallait encore enregistrer une clé API pour que le chat
     * réponde quoi que ce soit.
     */
    public function nextSteps(): void
    {
        // Accessible juste après la création du compte (laissez-passer en
        // session), ou plus tard à un administrateur connecté.
        $utilisateur = Session::currentUser();
        $estAdmin    = is_array($utilisateur) && ($utilisateur['role'] ?? '') === 'admin';
        if (!Session::get('setup_termine') && !$estAdmin) {
            header('Location: /auth/login');
            exit;
        }

        $etapes = [];

        $modeles = [];
        try {
            $modeles = LLMFactory::available();
        } catch (\Throwable $e) {
            ErrorHandler::journaliser($e);
        }
        $etapes[] = [
            'fait'   => $modeles !== [],
            'titre'  => 'Enregistrer une clé API de modèle',
            'detail' => $modeles === []
                ? "Sans clé, l'assistant ne peut rien répondre. C'est la seule étape indispensable."
                : count($modeles) . ' modèle(s) disponible(s).',
            'lien'   => '/admin/llms',
            'action' => 'Modèles IA',
        ];

        $embeddings = [];
        try {
            $embeddings = EmbeddingService::availableProviders();
        } catch (\Throwable $e) {
            ErrorHandler::journaliser($e);
        }
        $etapes[] = [
            'fait'   => $embeddings !== [],
            'titre'  => 'Activer la vectorisation des bases de connaissances',
            'detail' => $embeddings === []
                ? 'Facultatif. Sans elle, les bases de connaissances fonctionnent en recherche '
                    . 'lexicale, moins pertinente.'
                : 'Fournisseur : ' . implode(', ', array_keys($embeddings)) . '.',
            'lien'   => '/admin/llms',
            'action' => 'Clés API',
        ];

        $outils = 0;
        try {
            foreach (['image', 'video', 'html', 'presentation'] as $type) {
                $outils += count(ChatTool::active($type));
            }
        } catch (\Throwable $e) {
            ErrorHandler::journaliser($e);
        }
        $etapes[] = [
            'fait'   => $outils > 0,
            'titre'  => 'Configurer les outils image, vidéo et présentation',
            'detail' => $outils > 0 ? $outils . ' outil(s) actif(s).' : 'Facultatif.',
            'lien'   => '/admin/tools',
            'action' => 'Outils',
        ];

        $budget = false;
        try {
            $budget = AiBudget::config()['enabled'];
        } catch (\Throwable $e) {
            ErrorHandler::journaliser($e);
        }
        $etapes[] = [
            'fait'   => $budget,
            'titre'  => 'Fixer un budget IA mensuel',
            'detail' => $budget
                ? 'Budget en place : la page Budget suit la dépense réelle du mois.'
                : 'Facultatif. Plafonne ce que l\'IA peut coûter chaque mois et donne un repère de crédits par compte.',
            'lien'   => '/admin/budget',
            'action' => 'Coûts',
        ];

        // L'entreprise : ce que l'assistant sait de l'organisation dans chaque
        // conversation, et l'habillage des présentations téléchargées.
        $entreprise = CompanyProfile::name() !== '' || CompanyProfile::description() !== '';
        $etapes[] = [
            'fait'   => $entreprise,
            'titre'  => "Présenter l'entreprise à MIA",
            'detail' => $entreprise
                ? (CompanyProfile::templateInfo() !== null
                    ? 'Entreprise présentée, modèle PowerPoint déposé.'
                    : 'Entreprise présentée. Facultatif : son logo et son modèle PowerPoint habillent les présentations.')
                : "Facultatif. Son nom et son descriptif sont donnés à l'assistant dans chaque conversation ; "
                    . 'son modèle PowerPoint habille les présentations téléchargées.',
            'lien'   => '/admin/company',
            'action' => 'Entreprise',
        ];

        $etapes[] = [
            'fait'   => Settings::get('app_name', '') !== '',
            'titre'  => "Personnaliser le nom, le logo et les couleurs",
            'detail' => 'Facultatif.',
            'lien'   => '/admin/settings',
            'action' => 'Configuration',
        ];

        $etapes[] = [
            'fait'   => true,
            'titre'  => "Vérifier l'état de l'installation",
            'detail' => "La page Diagnostic liste chaque contrôle et son remède.",
            'lien'   => '/admin/diagnostics',
            'action' => 'Diagnostic',
        ];

        require ROOT . '/templates/setup/next-steps.php';
    }
}
