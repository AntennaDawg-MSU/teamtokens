<?php
// backend/config/db.php
// Copy this file to db.local.php and fill in real credentials.
// NEVER commit db.local.php to version control.

$local = __DIR__ . '/db.local.php';
if (file_exists($local)) {
    require $local;
} else {
    // Fallback: read from environment variables (recommended for production)
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '5432');
    define('DB_NAME', getenv('DB_NAME') ?: 'team_tokens');
    define('DB_USER', getenv('DB_USER') ?: 'tt_app');
    define('DB_PASS', getenv('DB_PASS') ?: '');
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            DB_HOST, DB_PORT, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
