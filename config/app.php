<?php

declare(strict_types=1);

// Chargement du .env (dev local sans Docker)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!isset($_ENV[$key]) && getenv($key) === false) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Lecture unifiée : variable d'environnement process (Docker) OU $_ENV (fichier .env)
function env(string $key, mixed $default = ''): mixed
{
    $v = getenv($key);
    if ($v !== false) return $v;
    return $_ENV[$key] ?? $default;
}

define('APP_URL',        env('APP_URL',        'http://localhost'));
define('APP_ENV',        env('APP_ENV',        'production'));
define('APP_SECRET',     env('APP_SECRET',     ''));
define('ENCRYPTION_KEY', env('ENCRYPTION_KEY', ''));

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'myllm'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

define('GOOGLE_CLIENT_ID',     env('GOOGLE_CLIENT_ID',     ''));
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET', ''));
define('GOOGLE_REDIRECT_URI',  env('GOOGLE_REDIRECT_URI',  APP_URL . '/auth/callback'));
// Client OAuth iOS de l'app mobile (audience des idTokens iOS). Pas de client
// Android côté PHP : Credential Manager utilise le client WEB en
// serverClientId, l'audience des idTokens Android est donc GOOGLE_CLIENT_ID.
define('GOOGLE_CLIENT_ID_IOS', env('GOOGLE_CLIENT_ID_IOS', ''));

define('LOCAL_PASSWORD_HASH', env('LOCAL_PASSWORD_HASH', ''));

// Base vectorielle embarquée (bases de connaissances). Défaut = service Docker
// interne ; en dev natif hors Docker : http://localhost:6333.
define('QDRANT_URL', rtrim((string) env('QDRANT_URL', 'http://qdrant:6333'), '/'));
// Volume qdrant_data monté en lecture seule dans le conteneur app (mesure
// exacte du stockage sur le dashboard admin). Dev natif hors Docker : pointer
// vers le répertoire storage de Qdrant, ou laisser tel quel (fallback
// estimation via l'API si le répertoire n'existe pas).
$qdrantDataPath = (string) env('QDRANT_DATA_PATH', '');
define('QDRANT_DATA_PATH', rtrim($qdrantDataPath !== '' ? $qdrantDataPath : '/qdrant-data', '/'));
unset($qdrantDataPath);

$versionFile = dirname(__DIR__) . '/VERSION';
define('APP_VERSION', is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0');

define('UPLOAD_DIR', dirname(__DIR__) . '/storage/uploads/');
define('LOG_DIR',    dirname(__DIR__) . '/storage/logs/');

define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10 Mo
define('UPLOAD_ALLOWED_TYPES', [
    'application/pdf',
    'text/plain',
    'text/markdown',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       // .xlsx
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
]);
