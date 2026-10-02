<?php

// Copy this file to platform.local.php (gitignored) and fill in your
// REAL Paystack/IntaSend test keys. Use TEST-mode keys (Paystack
// sk_test_..., IntaSend ISSecretKey_test.../ISPubKey_test...) until the
// verification checklist has been run end-to-end - never a live key
// during development.

return [
    'paystack_secret_key' => 'YOUR_TEST_SECRET_KEY',
    'default_percentage_charge' => 10,
    'intasend_secret_key' => 'YOUR_INTASEND_TEST_SECRET_KEY',
    'intasend_publishable_key' => 'YOUR_INTASEND_TEST_PUBLISHABLE_KEY',
    'intasend_webhook_challenge' => 'YOUR_INTASEND_WEBHOOK_CHALLENGE',
    // Create this mailbox first, then enable customer ticket emails.
    // The host's local mail transport sends from this address.
    'support_email_from' => 'support@nexapos.cc',
];
