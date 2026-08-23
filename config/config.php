<?php

$config = [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'nexapos_platform',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        // Managed MySQL hosts (Aiven, PlanetScale, etc.) require TLS -
        // set DB_SSL_CA to the CA bundle's path (baked into the Docker
        // image, see Dockerfile) to enable it. Left unset for local
        // XAMPP, which keeps connecting in plaintext same as always.
        'ssl_ca' => getenv('DB_SSL_CA') ?: null,
    ],
];

$localConfig = __DIR__ . '/local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
