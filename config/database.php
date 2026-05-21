<?php
declare(strict_types=1);

/**
 * Database connector — currently routed through the mysqli wrapper.
 *
 * The wrapper exposes a PDO-compatible API (db()->prepare()->execute()->fetch())
 * so the rest of the codebase keeps working unchanged.
 *
 * Rollback to native PDO: replace this file with config/database_pdo.php
 * (or change the require below).
 */

require_once __DIR__ . '/database_mysqli.php';
