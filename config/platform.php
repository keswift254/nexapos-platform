<?php

// paystack_secret_key must come from platform.local.php (gitignored) -
// never hardcode a real key here. Use a TEST-mode key (sk_test_...)
// until the verification checklist in docs/ has been run end-to-end.
$config = [
    'paystack_api_base' => getenv('PLATFORM_PAYSTACK_API_BASE') ?: 'https://api.paystack.co',
    'paystack_secret_key' => getenv('PLATFORM_PAYSTACK_SECRET_KEY') ?: '',
    // The platform's cut, as a percentage (Paystack's percentage_charge
    // field) - this is a business decision, not a technical default.
    // 10 is a placeholder only. See docs/verification-checklist.md item 1
    // before trusting this value with real money: confirm empirically
    // which side (platform vs subaccount) actually receives this share.
    'default_percentage_charge' => (float) (getenv('PLATFORM_DEFAULT_PERCENTAGE_CHARGE') ?: 10),
    'paystack_connect_timeout' => (int) (getenv('PLATFORM_PAYSTACK_CONNECT_TIMEOUT') ?: 25),
    'paystack_timeout' => (int) (getenv('PLATFORM_PAYSTACK_TIMEOUT') ?: 60),
    // How long a generate_invite code stays redeemable via join_shop.
    'sync_invite_expiry_minutes' => (int) (getenv('PLATFORM_SYNC_INVITE_EXPIRY_MINUTES') ?: 15),
    // Temporary hard ceiling for pre-batching app releases. Current
    // clients send 200; lower this to 200 after those releases dominate.
    'sync_legacy_batch_limit' => (int) (getenv('PLATFORM_SYNC_LEGACY_BATCH_LIMIT') ?: 1000),
    'sync_request_payload_limit_bytes' => (int) (getenv('PLATFORM_SYNC_REQUEST_LIMIT_BYTES') ?: 16777216),
    'sync_history_retention_days' => (int) (getenv('PLATFORM_SYNC_HISTORY_RETENTION_DAYS') ?: 90),
    'join_attempt_retention_days' => (int) (getenv('PLATFORM_JOIN_ATTEMPT_RETENTION_DAYS') ?: 7),
    'invite_retention_days' => (int) (getenv('PLATFORM_INVITE_RETENTION_DAYS') ?: 30),
    'maintenance_batch_size' => (int) (getenv('PLATFORM_MAINTENANCE_BATCH_SIZE') ?: 1000),
    'maintenance_max_batches' => (int) (getenv('PLATFORM_MAINTENANCE_MAX_BATCHES') ?: 10),
    // Gates list_all_devices (the cross-shop admin device dashboard) -
    // same value as nexapos_license's admin_secret by the user's own
    // choice, but a separate env var, so each service still owns its
    // own config independently. Never hardcode a real value here.
    'admin_secret' => getenv('PLATFORM_ADMIN_SECRET') ?: '',
    // Browser-hosted admin dashboard origins. Flutter/curl requests do
    // not send Origin and are unaffected by CORS.
    'cors_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('PLATFORM_CORS_ALLOWED_ORIGINS') ?: 'https://nexapos-license-1.onrender.com')
    ))),
];

$localConfig = __DIR__ . '/platform.local.php';
if (getenv('NEXAPOS_IGNORE_LOCAL_CONFIG') !== '1' && is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
