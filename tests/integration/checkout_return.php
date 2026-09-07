<?php
declare(strict_types=1);

require __DIR__ . '/../../app/Services/PaystackClient.php';
require __DIR__ . '/../../app/Core/Redactor.php';
putenv('NEXAPOS_IGNORE_LOCAL_CONFIG=1');
putenv('PLATFORM_PAYSTACK_SECRET_KEY=checkout-test-only');
// This test only contacts the local fake Paystack server started by the test runner.
putenv('PLATFORM_PAYSTACK_API_BASE=http://127.0.0.1:18089');
$config = require __DIR__ . '/../../config/platform.php';
if ($config['paystack_api_base'] !== 'http://127.0.0.1:18089' || $config['paystack_secret_key'] !== 'checkout-test-only') {
    throw new RuntimeException('Tests require the local fake Paystack configuration.');
}
$client = new Platform\Services\PaystackClient();
foreach ([false, true] as $returnToApp) {
    $response = $client->initializeTransaction(1234, 'TEST-CALLBACK', 'test@example.com', 'KES', 'ACCT_TEST', ['source' => 'test'], $returnToApp);
    $payload = $response['body']['data'];
    if ($payload['amount'] !== 1234 || $payload['subaccount'] !== 'ACCT_TEST') {
        throw new RuntimeException('Payment amount or settlement account changed.');
    }
    if ($returnToApp) {
        if (($payload['callback_url'] ?? '') !== 'https://keswift254.github.io/nexapos-site/checkout-return.html') {
            throw new RuntimeException('Missing fixed checkout callback.');
        }
    } elseif (isset($payload['callback_url'])) {
        throw new RuntimeException('Legacy clients must retain their existing callback behavior.');
    }
}
echo "Checkout callback tests passed; amount and subaccount preserved.\n";
