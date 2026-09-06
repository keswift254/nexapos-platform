<?php

namespace Platform\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO singleton, mirroring C:\xampp\htdocs\pos\app\Core\Database.php -
 * same auto-bootstrap-database-from-schema-file behavior, pointed at
 * this project's own sql/schema.sql and its own database.
 */
class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection) {
            return self::$connection;
        }
        $config = require __DIR__ . '/../../config/config.php';
        $db = $config['db'];
        $dsn = self::dsn($db, true);
        try {
            self::$connection = self::newPdo($dsn, $db);
        } catch (PDOException $exception) {
            if (!self::isUnknownDatabase($exception)) {
                throw $exception;
            }
            self::bootstrapDatabase($db);
            self::$connection = self::newPdo($dsn, $db);
        }
        self::runMigrations(self::$connection);
        return self::$connection;
    }

    private static function newPdo(string $dsn, array $db): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (!empty($db['ssl_ca'])) {
            if (!is_file($db['ssl_ca']) || !is_readable($db['ssl_ca'])) {
                throw new RuntimeException('DB_SSL_CA does not point to a readable CA certificate.');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $db['ssl_ca'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        return new PDO($dsn, $db['user'], $db['pass'], $options);
    }

    private static function dsn(array $db, bool $withDatabase): string
    {
        $port = (int) ($db['port'] ?? 3306);
        $dsn = "mysql:host={$db['host']};port={$port};charset={$db['charset']}";
        if ($withDatabase) {
            $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset={$db['charset']}";
        }
        return $dsn;
    }

    private static function isUnknownDatabase(PDOException $exception): bool
    {
        return strpos($exception->getMessage(), 'Unknown database') !== false
            || strpos($exception->getMessage(), '[1049]') !== false;
    }

    private static function bootstrapDatabase(array $db): void
    {
        $pdo = self::newPdo(self::dsn($db, false), $db);
        $dbName = self::quoteIdentifier($db['name']);
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', $db['charset']) ?: 'utf8mb4';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbName CHARACTER SET $charset COLLATE {$charset}_unicode_ci");

        $sqlPath = __DIR__ . '/../../sql/schema.sql';
        if (!is_file($sqlPath)) {
            throw new \RuntimeException("Schema file was not found: $sqlPath");
        }
        $sql = file_get_contents($sqlPath);
        if ($db['name'] !== 'nexapos_platform') {
            $sql = str_replace(
                'CREATE DATABASE IF NOT EXISTS nexapos_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
                "CREATE DATABASE IF NOT EXISTS $dbName CHARACTER SET $charset COLLATE {$charset}_unicode_ci;",
                $sql
            );
            $sql = str_replace('USE nexapos_platform;', "USE $dbName;", $sql);
        }
        foreach (self::splitSqlStatements($sql) as $statement) {
            $pdo->exec($statement);
        }

        $schema = $pdo->quote($db['name']);
        $clientsTableCount = (int) $pdo
            ->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = $schema AND TABLE_NAME = 'clients'")
            ->fetchColumn();
        if ($clientsTableCount < 1) {
            throw new \RuntimeException("Database bootstrap failed for {$db['name']}.");
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function runMigrations(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'nexapos_migrations_' . hash('sha1', $databaseName);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock.');
        }

        try {
            $paths = glob(__DIR__ . '/../../sql/migrations/*.sql') ?: [];
            sort($paths, SORT_STRING);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE name = ?');
            $record = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
            foreach ($paths as $path) {
                $name = basename($path);
                $exists->execute([$name]);
                if ((int) $exists->fetchColumn() > 0) {
                    continue;
                }
                $sql = file_get_contents($path);
                if ($sql === false) {
                    throw new RuntimeException("Could not read migration: $name");
                }
                foreach (self::splitSqlStatements($sql) as $statement) {
                    $pdo->exec($statement);
                }
                $record->execute([$name]);
            }
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;
            if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                if ($quote === $char) {
                    $quote = null;
                } elseif ($quote === null) {
                    $quote = $char;
                }
            }
            if ($char === ';' && $quote === null) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }
        return $statements;
    }
}
