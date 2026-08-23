<?php

// Copy this file to local.php (gitignored) to override the defaults in
// config.php for this machine - e.g. a non-default MySQL user/password.

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'nexapos_platform',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];
