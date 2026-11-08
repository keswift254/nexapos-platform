<?php
// A pretend Paystack for testing the Lite till against the REAL platform (PLATFORM_PAYSTACK_API_BASE points here).
// It remembers every initialised payment by reference and answers verification from what the test tells it:
//   POST /transaction/initialize          -> stores the request, returns a payment link (like Paystack)
//   GET  /transaction/verify/<reference>  -> the stored status (ongoing until the test changes it), amount, currency
//   GET  /__control/set?reference=..&status=success|abandoned|failed|ongoing[&amount=..][&currency=..]
//   GET  /__control/get?reference=..      -> what the platform sent Paystack (email, callback_url, amount, ...)
// State lives in a JSON file (FAKE_PAYSTACK_STATE, default: the system temp dir). Local testing only.

declare(strict_types=1);

header('Content-Type: application/json');
$stateFile = getenv('FAKE_PAYSTACK_STATE') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake_paystack_lite_state.json';
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state), LOCK_EX);
};
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';

if ($path === '/transaction/initialize' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode((string) file_get_contents('php://input'), true);
    $reference = (string) ($request['reference'] ?? '');
    if (!is_array($request) || $reference === '' || !isset($request['amount'], $request['email'])) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'Invalid initialise request']);
        return;
    }
    if (isset($state[$reference])) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'Duplicate Transaction Reference']);
        return;
    }
    $state[$reference] = ['request' => $request, 'status' => 'ongoing', 'amount' => (int) $request['amount'], 'currency' => (string) ($request['currency'] ?? 'KES')];
    $save();
    echo json_encode(['status' => true, 'message' => 'Authorization URL created', 'data' => [
        'authorization_url' => 'https://checkout.paystack.com/fake-' . substr(md5($reference), 0, 10),
        'access_code' => 'fake-' . substr(md5($reference), 0, 10),
        'reference' => $reference,
    ]]);
    return;
}

if (preg_match('#^/transaction/verify/([^/]+)$#', $path, $matches) === 1) {
    $reference = rawurldecode($matches[1]);
    if (!isset($state[$reference])) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'Transaction reference not found']);
        return;
    }
    $entry = $state[$reference];
    echo json_encode(['status' => true, 'message' => 'Verification successful', 'data' => [
        'id' => 9100000 + abs(crc32($reference)) % 100000,
        'status' => $entry['status'],
        'reference' => $reference,
        'amount' => $entry['amount'],
        'currency' => $entry['currency'],
    ]]);
    return;
}

if ($path === '/__control/set') {
    $reference = (string) ($_GET['reference'] ?? '');
    if (!isset($state[$reference])) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'unknown reference']);
        return;
    }
    if (isset($_GET['status'])) {
        $state[$reference]['status'] = (string) $_GET['status'];
    }
    if (isset($_GET['amount'])) {
        $state[$reference]['amount'] = (int) $_GET['amount'];
    }
    if (isset($_GET['currency'])) {
        $state[$reference]['currency'] = (string) $_GET['currency'];
    }
    $save();
    echo json_encode(['status' => true]);
    return;
}

if ($path === '/__control/get') {
    $reference = (string) ($_GET['reference'] ?? '');
    echo json_encode(['status' => isset($state[$reference]), 'entry' => $state[$reference] ?? null]);
    return;
}

if ($path === '/__control/reset') {
    $state = [];
    $save();
    echo json_encode(['status' => true]);
    return;
}

http_response_code(404);
echo json_encode(['status' => false, 'message' => 'Not found']);
