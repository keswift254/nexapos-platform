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
// [returnToApp, returnToWeb, expected callback_url or null for none]
$cases = [
    [false, false, null],
    [true, false, 'https://keswift254.github.io/nexapos-site/checkout-return.html'],
    [false, true, 'https://nexapos.cc/payment-return.html'],
    // If a client ever sent both, the browser must still come back to the browser.
    [true, true, 'https://nexapos.cc/payment-return.html'],
];
foreach ($cases as [$returnToApp, $returnToWeb, $expectedCallback]) {
    $label = 'app=' . var_export($returnToApp, true) . ' web=' . var_export($returnToWeb, true);
    $response = $client->initializeTransaction(1234, 'TEST-CALLBACK', 'test@example.com', 'KES', 'ACCT_TEST', ['source' => 'test'], $returnToApp, $returnToWeb);
    $payload = $response['body']['data'];
    if (($payload['transaction_charge'] ?? -1) !== 0 || ($payload['bearer'] ?? '') !== 'subaccount') {
        throw new RuntimeException("Merchant payments must have zero platform commission ($label).");
    }
    if ($payload['amount'] !== 1234 || $payload['subaccount'] !== 'ACCT_TEST') {
        throw new RuntimeException("Payment amount or settlement account changed ($label).");
    }
    if ($expectedCallback === null) {
        if (isset($payload['callback_url'])) {
            throw new RuntimeException("Legacy clients must retain their existing callback behavior ($label).");
        }
    } elseif (($payload['callback_url'] ?? '') !== $expectedCallback) {
        throw new RuntimeException("Wrong checkout callback ($label): " . var_export($payload['callback_url'] ?? null, true));
    }
}
echo "Checkout callback tests passed; amount and subaccount preserved.\n";

// The email Paystack sees: never the shop's shared one, valid, and distinct per customer / payment.
$domain = '@' . Platform\Services\PaystackClient::CUSTOMER_EMAIL_DOMAIN;
$emailCases = [
    // [shop, reference, phone, expected address or null (= just check the rules)]
    [7, 'S-0001', '0712 345 678', '254712345678' . $domain],
    [7, 'S-0002', '+254 712-345-678', '254712345678' . $domain],
    [9, 'S-0001', '712345678', '254712345678' . $domain],
    [7, 'S-0003', '08012345668', '08012345668' . $domain],
];
foreach ($emailCases as [$shop, $reference, $phone, $expected]) {
    $got = Platform\Services\PaystackClient::customerEmail($shop, $reference, $phone);
    if ($got !== $expected) {
        throw new RuntimeException("Phone $phone gave $got, expected $expected.");
    }
}
$a = Platform\Services\PaystackClient::customerEmail(7, 'SALE-20261105-0001');
$b = Platform\Services\PaystackClient::customerEmail(7, 'SALE-20261105-0002');
$otherShop = Platform\Services\PaystackClient::customerEmail(8, 'SALE-20261105-0001');
foreach ([$a, $b, $otherShop, Platform\Services\PaystackClient::customerEmail(7, '###', ''), Platform\Services\PaystackClient::customerEmail(7, str_repeat('x', 200))] as $address) {
    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || strlen(explode('@', $address)[0]) > 64) {
        throw new RuntimeException("Not a valid address: $address");
    }
}
if (count(array_unique([$a, $b, $otherShop])) !== 3) {
    throw new RuntimeException('Two payments shared one address.');
}
if ($a !== Platform\Services\PaystackClient::customerEmail(7, 'SALE-20261105-0001')) {
    throw new RuntimeException('The same payment must always give the same address.');
}
if (Platform\Services\PaystackClient::customerEmail(7, 'S-1', '123') === Platform\Services\PaystackClient::customerEmail(7, 'S-2', '123')) {
    throw new RuntimeException('A too-short phone number must not be treated as a customer.');
}
echo "Customer email tests passed; no shared address, always valid.\n";
