<?php

declare(strict_types=1);

// Verifies that DB_INIT_COMMAND is applied to every new connection (session time zone and
// sql_mode are how a new database host is made to behave exactly like the old one).
// Run: DB_NAME=<throwaway> php tests/verify_db_init_command.php   (needs a local MySQL)

spl_autoload_register(function (string $class): void {
    $prefix = 'Platform\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

putenv('NEXAPOS_IGNORE_LOCAL_CONFIG=1');
putenv("DB_INIT_COMMAND=SET time_zone = '+03:00', sql_mode = 'STRICT_ALL_TABLES'");

$pdo = Platform\Core\Database::connection();
$row = $pdo->query('SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode')->fetch();
if ($row['tz'] !== '+03:00' || $row['mode'] !== 'STRICT_ALL_TABLES') {
    fwrite(STDERR, 'FAIL: init command was not applied: ' . json_encode($row) . "\n");
    exit(1);
}
echo "init command applied: tz={$row['tz']} sql_mode={$row['mode']}\n";
