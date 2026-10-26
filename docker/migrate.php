<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// Charger les variables d'environnement depuis $_ENV (injectées par Docker)
$required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_SECRET', 'ENCRYPTION_KEY'];
foreach ($required as $key) {
    if (!isset($_ENV[$key]) && !getenv($key)) {
        fwrite(STDERR, "[migrate] Variable manquante : $key\n");
        exit(1);
    }
    if (!defined($key)) {
        define($key, getenv($key) ?: $_ENV[$key]);
    }
}

// Définir les constantes attendues par Database.php
if (!defined('DB_HOST'))       define('DB_HOST',       getenv('DB_HOST'));
if (!defined('DB_PORT'))       define('DB_PORT',       (int)(getenv('DB_PORT') ?: 3306));
if (!defined('DB_NAME'))       define('DB_NAME',       getenv('DB_NAME'));
if (!defined('DB_USER'))       define('DB_USER',       getenv('DB_USER'));
if (!defined('DB_PASS'))       define('DB_PASS',       getenv('DB_PASS'));
if (!defined('APP_SECRET'))    define('APP_SECRET',    getenv('APP_SECRET'));
if (!defined('ENCRYPTION_KEY'))define('ENCRYPTION_KEY',getenv('ENCRYPTION_KEY'));

require ROOT . '/src/Models/Database.php';

try {
    Database::getInstance();
    echo "[migrate] OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] ERREUR : " . $e->getMessage() . "\n");
    exit(1);
}
