<?php

declare(strict_types=1);

require __DIR__ . '/../../app/Core/Database.php';

try {
    Platform\Core\Database::connection();
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'readable CA certificate')) {
        echo "TLS configuration failed closed as expected.\n";
        exit(0);
    }
    throw $e;
}

fwrite(STDERR, "Database connection unexpectedly accepted an unreadable CA path.\n");
exit(1);
