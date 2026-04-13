<?php

namespace App\Services;

use App\Models\BillingSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NowPaymentsClient
{
    public function baseUrl(BillingSetting $settings): string
    {
        return $settings->nowpayments_sandbox
            ? 'https://api-sandbox.nowpayments.io/v1'
            : 'https://api.nowpayments.io/v1';
    }

    /**
     * @return array<string, mixed>
     */
    public function createPayment(BillingSetting $settings, array $payload): array
    {
        $key = $settings->nowpayments_api_key;
        if ($key === null || $key === '') {
            throw new RuntimeException('NOWPayments API key is not configured.');
        }

        $url = $this->baseUrl($settings).'/payment';
        $response = Http::withHeaders([
            'x-api-key' => $key,
            'Content-Type' => 'application/json',
        ])
            ->acceptJson()
            ->timeout(30)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('NOWPayments create payment failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Payment provider error: '.$response->body());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * NOWPayments signs a JSON string of the payload with recursively sorted object keys.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyIpnSignature(array $payload, string $signature, BillingSetting $settings): bool
    {
        $secret = $settings->nowpayments_ipn_secret;
        if ($secret === null || $secret === '') {
            return false;
        }

        $sorted = self::sortKeysRecursive($payload);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $expected = hash_hmac('sha512', $json, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function sortKeysRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::sortKeysRecursive($value);
            }
        }

        return $data;
    }
}
