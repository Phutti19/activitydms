<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $_ENV['DB_HOST'],
        $_ENV['DB_PORT'],
        $_ENV['DB_NAME'],
        $_ENV['DB_CHARSET']
    );

    $options = [
        PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES     => false,  // native prepared statements
        Pdo\Mysql::ATTR_INIT_COMMAND   => "SET NAMES " . $_ENV['DB_CHARSET'] . " COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options);
    } catch (PDOException $e) {
        if (APP_DEBUG) {
            throw $e;
        }
        error_log('[DB] Connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Service temporarily unavailable.');
    }

    return $pdo;
}
