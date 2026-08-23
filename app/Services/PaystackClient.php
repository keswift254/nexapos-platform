<?php

namespace Platform\Services;

use Platform\Core\Redactor;
use RuntimeException;

/**
 * All outbound calls to Paystack, using the platform's own secret key.
 * This is the only class in the whole project that ever sees that key.
 *
 * Amounts are passed through untouched (already minor units/cents from
 * the caller) - unlike the sibling pos app's PaystackService, which
 * converts a major-unit float via amountToMinorUnits(). Copying that
 * conversion here would silently multiply every charge by 100.
 */
class PaystackClient
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/platform.php';
        if (trim((string) $this->config['paystack_secret_key']) === '') {
            throw new RuntimeException('Platform Paystack secret key is not configured.');
        }
    }

    public function createSubaccount(string $businessName, string $bankCode, string $accountNumber, float $percentageCharge): array
    {
        return $this->request('POST', '/subaccount', [
            'business_name' => $businessName,
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
            'percentage_charge' => $percentageCharge,
        ]);
    }

    /**
     * Paystack requires 'description' on update (unlike create, where
     * it's optional) - business_name doubles as a reasonable default
     * rather than adding a field callers have no other use for.
     */
    public function updateSubaccount(string $subaccountCode, string $businessName, string $bankCode, string $accountNumber, float $percentageCharge): array
    {
        return $this->request('PUT', '/subaccount/' . rawurlencode($subaccountCode), [
            'business_name' => $businessName,
            'description' => $businessName,
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
            'percentage_charge' => $percentageCharge,
        ]);
    }

    public function initializeTransaction(int $amountMinor, string $reference, string $email, string $currency, string $subaccountCode, array $metadata): array
    {
        return $this->request('POST', '/transaction/initialize', [
            'email' => $email,
            'amount' => $amountMinor,
            'currency' => $currency,
            'reference' => $reference,
            'subaccount' => $subaccountCode,
            'metadata' => $metadata,
        ]);
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    public function listBanks(): array
    {
        return $this->request('GET', '/bank?country=kenya&perPage=100');
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim((string) $this->config['paystack_api_base'], '/') . '/' . ltrim($path, '/');
        $hasBody = $method === 'POST' || $method === 'PUT';
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . trim((string) $this->config['paystack_secret_key']),
        ];
        if ($hasBody) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(10, (int) $this->config['paystack_connect_timeout']),
            CURLOPT_TIMEOUT => max(30, (int) $this->config['paystack_timeout']),
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
        ];
        if (defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if ($hasBody) {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Paystack request failed: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Paystack response was invalid.');
        }
        $this->log($method . ' ' . $path, $httpCode, $payload ?? [], $data);

        return ['http_code' => $httpCode, 'body' => $data];
    }

    private function log(string $action, int $httpCode, array $request, array $response): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $entry = [
            'time' => date('c'),
            'action' => $action,
            'http_code' => $httpCode,
            'request' => Redactor::redact($request),
            'response' => Redactor::redact($response),
        ];
        @file_put_contents($logDir . '/paystack-platform.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}
