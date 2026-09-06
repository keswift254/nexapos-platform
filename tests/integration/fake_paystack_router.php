<?php

header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
if (preg_match('#/transaction/verify/([^/]+)$#', $path, $matches) === 1) {
    $reference = rawurldecode($matches[1]);
    echo json_encode([
        'status' => true,
        'message' => 'Verification successful',
        'data' => [
            'id' => 9000001,
            'status' => 'success',
            'reference' => $reference,
            'amount' => 1000,
            'currency' => 'KES',
        ],
    ]);
    return;
}

http_response_code(404);
echo json_encode(['status' => false, 'message' => 'Not found']);
