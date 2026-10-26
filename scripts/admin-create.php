<?php

declare(strict_types=1);

/**
 * Crée (ou réinitialise) un compte administrateur.
 *
 *   php scripts/admin-create.php --email=vous@exemple.fr [--name="Nom affiché"] [--reset]
 *
 * Le mot de passe est lu sur l'ENTRÉE STANDARD, jamais passé en argument : un
 * argument apparaîtrait dans `ps`, dans l'historique du shell et dans les
 * journaux de supervision.
 *
 *   printf '%s' "$MOT_DE_PASSE" | php scripts/admin-create.php --email=…
 *   docker compose exec -T app php scripts/admin-create.php --email=…
 *
 * Remplace le hash bcrypt que l'installeur écrivait dans LOCAL_PASSWORD_HASH :
 * cette variable traversait l'interpolation « $ » de docker compose, qui la
 * tronquait silencieusement. Un compte en base n'a pas ce défaut, se
 * réinitialise depuis l'interface, et laisse une trace dans `users`.
 *
 * Codes de sortie : 0 créé ou réinitialisé · 2 déjà existant sans --reset · 1 erreur.
 */

require __DIR__ . '/_cli_bootstrap.php';

$options = getopt('', ['email:', 'name::', 'reset']);

$email = trim((string) ($options['email'] ?? ''));
$nom   = trim((string) ($options['name'] ?? '')) ?: 'Administrateur';
$reset = array_key_exists('reset', $options);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Adresse e-mail absente ou invalide. Usage :\n"
        . "  printf '%s' \"\$MOT_DE_PASSE\" | php scripts/admin-create.php --email=vous@exemple.fr\n");
    exit(1);
}

$motDePasse = stream_get_contents(STDIN);
$motDePasse = $motDePasse === false ? '' : trim($motDePasse, "\r\n");

if (strlen($motDePasse) < 8) {
    fwrite(STDERR, "Mot de passe absent ou trop court (8 caractères minimum).\n"
        . "Il se lit sur l'entrée standard, pas en argument.\n");
    exit(1);
}

try {
    $db = Database::getInstance();

    // Recherche par e-mail SEUL, sans passer par User::findByEmail : celui-ci
    // filtre sur `password_hash IS NOT NULL` et laisserait donc échapper un
    // compte créé via Google. On heurterait alors l'index unique uq_users_email
    // avec une erreur SQL brute au lieu d'un message clair.
    $requete = $db->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
    $requete->execute([$email]);
    $existant = $requete->fetch() ?: null;

    if ($existant !== null && !$reset) {
        fwrite(STDERR, 'Un compte existe déjà pour ' . $email . ".\n"
            . "Relancez avec --reset pour redéfinir son mot de passe et lui donner le rôle administrateur.\n");
        exit(2);
    }

    if ($existant !== null) {
        $db->prepare(
            'UPDATE users SET password_hash = ?, name = ?, role = \'admin\', is_active = 1 WHERE id = ?'
        )->execute([
            password_hash($motDePasse, PASSWORD_BCRYPT),
            $nom,
            $existant['id'],
        ]);

        fwrite(STDOUT, 'Compte administrateur réinitialisé : ' . $email . PHP_EOL);
        exit(0);
    }

    $utilisateur = User::createWithPassword($nom, $email, $motDePasse, 'admin', 1);

    if (!is_array($utilisateur) || !isset($utilisateur['id'])) {
        fwrite(STDERR, "La création du compte a échoué. Consultez storage/logs/errors.log.\n");
        exit(1);
    }

    fwrite(STDOUT, 'Compte administrateur créé : ' . $email . PHP_EOL);
    exit(0);
} catch (\Throwable $e) {
    // ErrorHandler journalise déjà la trace ; ici on veut un message court et
    // actionnable pour l'installeur, qui affiche cette sortie telle quelle.
    fwrite(STDERR, 'Impossible de créer le compte : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
