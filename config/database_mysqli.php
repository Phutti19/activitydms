<?php
declare(strict_types=1);

/**
 * MySQLi wrapper that mimics the PDO API used in this project.
 *
 * Goal: drop-in replacement so the 35 existing PDO call sites do NOT need
 * to be rewritten. Existing code keeps calling:
 *     db()->prepare(...)->execute([...])->fetch()
 * but mysqli runs underneath.
 *
 * Supported PDO surface:
 *   - db()->prepare($sql) / db()->query($sql) / db()->exec($sql)
 *   - db()->lastInsertId()
 *   - db()->beginTransaction() / commit() / rollBack()
 *   - $stmt->execute([...]) with both ? and :name params
 *   - $stmt->bindValue($k, $v, PDO::PARAM_*)
 *   - $stmt->fetch() / fetchAll() / fetchColumn() / rowCount()
 *   - $stmt->fetchAll(PDO::FETCH_COLUMN)
 *
 * Anything outside this surface (PDO::FETCH_KEY_PAIR, FETCH_OBJ, etc.)
 * will throw — by design, so we notice unsupported usage early.
 */

require_once __DIR__ . '/config.php';

/**
 * Exception class that mimics PDOException so existing
 *   catch (PDOException $e) { if ($e->getCode() === '23000') ... }
 * blocks (6 sites in this project) keep working unchanged.
 *
 * PDOException::$code is a SQLSTATE string ('23000', '42S02', ...) while
 * mysqli_sql_exception::getCode() returns the numeric MySQL error (1062, ...).
 * We rewrite $code to SQLSTATE so behavior matches PDO exactly.
 */
final class DBException extends PDOException
{
    public function __construct(mysqli_sql_exception $prev, string $context = '')
    {
        $msg = $context !== '' ? "$context: " . $prev->getMessage() : $prev->getMessage();
        parent::__construct($msg, 0, $prev);
        // PDOException stores SQLSTATE in $code (string). Match that.
        $this->code = $prev->getSqlState() ?: 'HY000';
    }
}

final class DB
{
    private mysqli $conn;
    private bool $in_transaction = false;

    public function __construct()
    {
        // Throw exceptions on error (matches PDO::ERRMODE_EXCEPTION)
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->conn = new mysqli(
                $_ENV['DB_HOST'],
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD'],
                $_ENV['DB_NAME'],
                (int) $_ENV['DB_PORT']
            );
            $this->conn->set_charset($_ENV['DB_CHARSET']);
            // Match PDO's init: collation for Thai + emoji
            $this->conn->query("SET NAMES " . $_ENV['DB_CHARSET'] . " COLLATE utf8mb4_unicode_ci");
        } catch (mysqli_sql_exception $e) {
            if (APP_DEBUG) {
                throw new DBException($e, 'Connection failed');
            }
            error_log('[DB] Connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Service temporarily unavailable.');
        }
    }

    public function prepare(string $sql): DBStatement
    {
        return new DBStatement($this->conn, $sql);
    }

    /** PDO::query() — prepare + execute in one shot, returns Statement */
    public function query(string $sql): DBStatement
    {
        $stmt = new DBStatement($this->conn, $sql);
        $stmt->execute();
        return $stmt;
    }

    /** PDO::exec() — run DDL/DML with no result, return affected rows */
    public function exec(string $sql): int
    {
        $this->conn->query($sql);
        return $this->conn->affected_rows;
    }

    public function lastInsertId(): string
    {
        return (string) $this->conn->insert_id;
    }

    public function beginTransaction(): bool
    {
        $this->conn->begin_transaction();
        $this->in_transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $ok = $this->conn->commit();
        $this->in_transaction = false;
        return $ok;
    }

    public function rollBack(): bool
    {
        $ok = $this->conn->rollback();
        $this->in_transaction = false;
        return $ok;
    }

    public function inTransaction(): bool
    {
        return $this->in_transaction;
    }

    public function getMysqli(): mysqli
    {
        return $this->conn;
    }
}

final class DBStatement
{
    private mysqli $conn;
    private string $original_sql;
    private string $converted_sql;
    /** @var string[] order of :name params after conversion (positional ?) */
    private array $name_order = [];
    private ?mysqli_stmt $stmt = null;
    /** @var array<int|string,mixed> values bound via bindValue() */
    private array $bound = [];
    /** @var array<int|string,int> explicit PDO::PARAM_* types from bindValue */
    private array $bound_types = [];
    private ?mysqli_result $result = null;
    private int $affected_rows = 0;

    public function __construct(mysqli $conn, string $sql)
    {
        $this->conn         = $conn;
        $this->original_sql = $sql;
        // Convert :name placeholders to ? and remember the order
        [$this->converted_sql, $this->name_order] = self::convertNamedPlaceholders($sql);
    }

    /**
     * Bind one value for later execute().
     * Mirrors PDO::bindValue() signature: $param can be ":name" or 1-based int.
     */
    public function bindValue(int|string $param, mixed $value, int $type = 2 /* PDO::PARAM_STR */): bool
    {
        // Normalize :name → name, 1-based int → 0-based
        if (is_string($param)) {
            $key = ltrim($param, ':');
        } else {
            $key = $param - 1;
        }
        $this->bound[$key]       = $value;
        $this->bound_types[$key] = $type;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        // Free any prior result so re-execute on a cached stmt is clean
        if ($this->result !== null) {
            $this->result->free();
            $this->result = null;
        }

        // Merge bindValue() entries with execute([...]) entries.
        // PDO allows mixing — execute params win on conflict.
        $merged_values = $this->bound;
        $merged_types  = $this->bound_types;

        if ($params !== null) {
            $is_list = array_is_list($params);
            foreach ($params as $k => $v) {
                if (is_string($k)) {
                    // execute([':name' => ...]) or execute(['name' => ...])
                    $key = ltrim($k, ':');
                } elseif ($is_list) {
                    // execute([$a, $b]) — already 0-based positional
                    $key = $k;
                } else {
                    // execute([1 => $a, 2 => $b]) — PDO is 1-based, convert to 0-based
                    $key = $k - 1;
                }
                $merged_values[$key] = $v;
            }
        }

        // Reorder to match converted_sql's positional ? sequence
        $ordered_values = [];
        if (!empty($this->name_order)) {
            // Named-style: order by name_order
            foreach ($this->name_order as $i => $name) {
                if (!array_key_exists($name, $merged_values)) {
                    throw new RuntimeException("Missing param :$name for SQL: {$this->original_sql}");
                }
                $ordered_values[$i] = $merged_values[$name];
            }
            // Build types in same order, using explicit type if bound, else auto
            $types = '';
            foreach ($this->name_order as $i => $name) {
                $types .= self::resolveType(
                    $ordered_values[$i],
                    $merged_types[$name] ?? null
                );
            }
        } else {
            // Positional style — use values as-is (0-based)
            $count = substr_count($this->converted_sql, '?');
            for ($i = 0; $i < $count; $i++) {
                if (!array_key_exists($i, $merged_values)) {
                    throw new RuntimeException("Missing positional param #" . ($i + 1) . " for SQL: {$this->original_sql}");
                }
                $ordered_values[$i] = $merged_values[$i];
            }
            $types = '';
            for ($i = 0; $i < $count; $i++) {
                $types .= self::resolveType(
                    $ordered_values[$i],
                    $merged_types[$i] ?? null
                );
            }
        }

        // Prepare ONCE per statement, then reuse — matches PDO semantics
        // and avoids re-preparing inside loops (e.g. bulk INSERTs).
        if ($this->stmt === null) {
            try {
                $stmt = $this->conn->prepare($this->converted_sql);
            } catch (mysqli_sql_exception $e) {
                throw new DBException($e, 'Prepare failed for: ' . $this->original_sql);
            }
            if ($stmt === false) {
                throw new RuntimeException('Prepare failed: ' . $this->conn->error . ' :: ' . $this->original_sql);
            }
            $this->stmt = $stmt;
        }

        if (!empty($ordered_values)) {
            $this->stmt->bind_param($types, ...$ordered_values);
        }

        try {
            $ok = $this->stmt->execute();
        } catch (mysqli_sql_exception $e) {
            throw new DBException($e, 'Execute failed for: ' . $this->original_sql);
        }

        $this->result        = $this->stmt->get_result() ?: null;
        $this->affected_rows = $this->stmt->affected_rows;
        return $ok;
    }

    public function __destruct()
    {
        if ($this->result !== null) {
            $this->result->free();
        }
        if ($this->stmt !== null) {
            $this->stmt->close();
        }
    }

    public function fetch(int $mode = 2 /* PDO::FETCH_ASSOC */): array|false
    {
        if ($this->result === null) {
            return false;
        }
        if ($mode !== 2) {
            throw new RuntimeException("Unsupported fetch mode: $mode (wrapper supports FETCH_ASSOC only)");
        }
        $row = $this->result->fetch_assoc();
        return $row === null ? false : $row;
    }

    public function fetchAll(int $mode = 2 /* PDO::FETCH_ASSOC */, int $column = 0): array
    {
        if ($this->result === null) {
            return [];
        }
        // PDO::FETCH_ASSOC === 2, FETCH_COLUMN === 7
        if ($mode === 7) {
            $out = [];
            while ($row = $this->result->fetch_array(MYSQLI_NUM)) {
                $out[] = $row[$column] ?? null;
            }
            return $out;
        }
        if ($mode !== 2) {
            throw new RuntimeException("Unsupported fetch mode: $mode (wrapper supports FETCH_ASSOC and FETCH_COLUMN only)");
        }
        return $this->result->fetch_all(MYSQLI_ASSOC);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->result === null) {
            return false;
        }
        $row = $this->result->fetch_array(MYSQLI_NUM);
        if ($row === null || $row === false) {
            return false;
        }
        return $row[$column] ?? false;
    }

    public function rowCount(): int
    {
        // PDO::rowCount() returns affected_rows for INSERT/UPDATE/DELETE,
        // and num_rows for SELECT (driver-dependent but mysql does this).
        if ($this->result !== null) {
            return (int) $this->result->num_rows;
        }
        return (int) $this->affected_rows;
    }

    public function closeCursor(): bool
    {
        if ($this->result !== null) {
            $this->result->free();
            $this->result = null;
        }
        return true;
    }

    /** Convert :name placeholders → ? and capture their order. */
    private static function convertNamedPlaceholders(string $sql): array
    {
        $order = [];
        // Match :name but skip ::cast (PostgreSQL-style), quoted strings, and comments.
        // Project uses MySQL + simple :name params, so this regex is sufficient.
        $converted = preg_replace_callback(
            '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($m) use (&$order) {
                $order[] = $m[1];
                return '?';
            },
            $sql
        );
        return [$converted, $order];
    }

    /** Auto-detect mysqli bind type letter, or honor explicit PDO::PARAM_*. */
    private static function resolveType(mixed $value, ?int $pdo_type): string
    {
        // PDO::PARAM_INT === 1, PARAM_STR === 2, PARAM_BOOL === 5, PARAM_NULL === 0, PARAM_LOB === 3
        if ($pdo_type !== null) {
            return match ($pdo_type) {
                1, 5    => 'i',   // INT, BOOL
                3       => 'b',   // LOB
                default => 's',   // STR, NULL, anything else → string
            };
        }
        // Auto-detect from PHP type
        if (is_int($value) || is_bool($value)) return 'i';
        if (is_float($value))                  return 'd';
        // null binds fine with 's' in mysqli
        return 's';
    }
}

/** Singleton accessor — same name as the PDO version, so existing call sites just work. */
function db(): DB
{
    static $instance = null;
    if ($instance === null) {
        $instance = new DB();
    }
    return $instance;
}
