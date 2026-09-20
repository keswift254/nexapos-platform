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
        self::runMigrations(self::$connection, $db);
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

        // sql/schema.sql is kept in sync with every migration under
        // sql/migrations/ as it's written (this repo's own established
        // convention - a fresh install gets the combined result of both
        // at once, rather than schema.sql plus a long replay of history).
        // That means a database just bootstrapped from schema.sql
        // already has the effect of every migration that existed at the
        // time this code shipped - runMigrations() must not try to
        // re-apply them right afterward (e.g. a second ADD COLUMN for
        // something schema.sql already includes fails outright), so mark
        // them applied here, before runMigrations() ever runs against
        // this connection.
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $record = $pdo->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (?)');
        foreach (glob(__DIR__ . '/../../sql/migrations/*.sql') ?: [] as $path) {
            $record->execute([basename($path)]);
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * How long a "migrations are up to date" note is trusted before the
     * database is asked again (see runMigrations).
     */
    private const MIGRATION_NOTE_TTL_SECONDS = 6 * 3600;

    private static function runMigrations(PDO $pdo, array $db): void
    {
        $paths = glob(__DIR__ . '/../../sql/migrations/*.sql') ?: [];
        sort($paths, SORT_STRING);

        // This used to run on EVERY request: CREATE TABLE, SELECT DATABASE(),
        // GET_LOCK, one "already applied?" query PER migration file, RELEASE_LOCK.
        // With the database in another data centre each of those round trips costs
        // real time, and together they added ~4 seconds to every single API call -
        // including each page of a shop's first download. Once this process has seen
        // every migration applied it leaves a small note in the temp directory (keyed
        // by database and by the exact list of migration files, so a new migration
        // invalidates it) and later requests skip all of it. The note expires after a
        // few hours so a database restored from an old backup gets re-checked.
        $note = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexapos_platform_schema_' . sha1(
            ($db['host'] ?? '') . '|' . ($db['port'] ?? '') . '|' . ($db['name'] ?? '') . '|'
            . implode(',', array_map('basename', $paths))
        );
        $noteTime = @filemtime($note);
        if ($noteTime !== false && (time() - $noteTime) < self::MIGRATION_NOTE_TTL_SECONDS) {
            return;
        }

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
            // One query for the whole applied list, not one per migration file.
            $applied = array_flip($pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
            $record = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
            foreach ($paths as $path) {
                $name = basename($path);
                if (isset($applied[$name])) {
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
            @file_put_contents($note, gmdate('c'));
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /**
     * Splits a multi-statement .sql file on unquoted, uncommented
     * semicolons. Must recognize both "--" line comments and C-style
     * slash-star block comments as such - not just track quote
     * characters - because this project's own migration files routinely
     * have both apostrophes ("doesn't", "shop's") and literal semicolons
     * inside comment prose. An earlier version only tracked quotes: an
     * odd apostrophe count across a run of comments desynced its
     * quote-tracking and silently merged unrelated CREATE TABLE
     * statements into one malformed blob, and a semicolon inside a
     * comment split a statement in half - both confirmed for real
     * against this project's own schema.sql, not hypothetical. See
     * tests/verify_split_sql_statements.php.
     */
    private static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $inLineComment = false;
        $inBlockComment = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '/' && $i > 0 && $sql[$i - 1] === '*') {
                    $inBlockComment = false;
                }
                continue;
            }
            if ($quote === null && $char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $inLineComment = true;
                continue;
            }
            if ($quote === null && $char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $inBlockComment = true;
                continue;
            }

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
