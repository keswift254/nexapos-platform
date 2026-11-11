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
// [returnToApp, returnToWeb, returnToLite, expected callback_url or null for none]
$cases = [
    [false, false, false, null],
    [true, false, false, 'https://keswift254.github.io/nexapos-site/checkout-return.html'],
    [false, true, false, 'https://nexapos.cc/payment-return.html'],
    // If a client ever sent both, the browser must still come back to the browser.
    [true, true, false, 'https://nexapos.cc/payment-return.html'],
    // The Lite till has its own plain page, whatever else was asked.
    [false, false, true, 'https://nexapos.cc/lite/paid.html'],
    [true, true, true, 'https://nexapos.cc/lite/paid.html'],
];
foreach ($cases as [$returnToApp, $returnToWeb, $returnToLite, $expectedCallback]) {
    $label = 'app=' . var_export($returnToApp, true) . ' web=' . var_export($returnToWeb, true) . ' lite=' . var_export($returnToLite, true);
    $response = $client->initializeTransaction(1234, 'TEST-CALLBACK', 'test@example.com', 'KES', 'ACCT_TEST', ['source' => 'test'], $returnToApp, $returnToWeb, $returnToLite);
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
$email = static fn (int $shop, int $number, string $phone = ''): string => Platform\Services\PaystackClient::customerEmail($shop, $number, $phone);
$emailCases = [
    // [shop, running number, phone, expected address]
    [7, 42, '', 's7-0042' . $domain],
    [7, 5, '', 's7-0005' . $domain],
    [12, 12345, '', 's12-12345' . $domain],
    [7, 0, '', 's7-0000' . $domain],
    // A phone number, when one is known, makes the address the customer's own (normalised).
    [7, 42, '0712 345 678', '254712345678' . $domain],
    [7, 43, '+254 712-345-678', '254712345678' . $domain],
    [9, 1, '712345678', '254712345678' . $domain],
    [7, 44, '08012345668', '08012345668' . $domain],
    // Too short to be a phone number: treated as no phone at all.
    [7, 45, '123', 's7-0045' . $domain],
];
foreach ($emailCases as [$shop, $number, $phone, $expected]) {
    $got = $email($shop, $number, $phone);
    if ($got !== $expected) {
        throw new RuntimeException("Shop $shop payment $number phone '$phone' gave $got, expected $expected.");
    }
    if (filter_var($got, FILTER_VALIDATE_EMAIL) === false || strlen(explode('@', $got)[0]) > 64) {
        throw new RuntimeException("Not a valid address: $got");
    }
}
if ($email(7, 42) === $email(8, 42) || $email(7, 42) === $email(7, 43)) {
    throw new RuntimeException('Two payments shared one address.');
}
if ($email(7, 42) !== $email(7, 42)) {
    throw new RuntimeException('The same payment must always give the same address.');
}
echo "Customer email tests passed; short, valid, and never shared.\n";
// A Kenyan mobile number as Paystack wants it for an M-Pesa prompt: cleaned from what a cashier types, and never
// anything that is not a Kenyan mobile.
$phoneCases = [
    ['0712 345 678', '+254712345678'],
    ['0712345678', '+254712345678'],
    ['712345678', '+254712345678'],
    ['254712345678', '+254712345678'],
    ['+254 712-345-678', '+254712345678'],
    ['(0112) 345 678', '+254112345678'],
    ['0112345678', '+254112345678'],
    // Not Kenyan mobiles: nothing may be sent to these.
    ['', null],
    ['12345', null],
    ['0212345678', null],       // a landline-style 02 number
    ['0612345678', null],
    ['+255712345678', null],    // Tanzania
    ['+2547123456789', null],   // one digit too many
    ['07123456', null],         // one digit too few
    ['abcdefghij', null],
];
foreach ($phoneCases as [$typed, $expected]) {
    $got = Platform\Services\PaystackClient::mpesaPhone($typed);
    if ($got !== $expected) {
        throw new RuntimeException("mpesaPhone('$typed') gave " . var_export($got, true) . ', expected ' . var_export($expected, true));
    }
}
echo "M-Pesa phone number tests passed; only Kenyan mobiles are accepted, always as +254...\n";
