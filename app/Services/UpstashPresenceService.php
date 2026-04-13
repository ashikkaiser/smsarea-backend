<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

final class UpstashPresenceService
{
    public function getString(string $key): ?string
    {
        $map = $this->getMany([$key]);

        return $map[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string|null>
     */
    public function getMany(array $keys): array
    {
        $base = rtrim((string) config('services.sms_gateway.upstash_rest_url'), '/');
        $token = (string) config('services.sms_gateway.upstash_rest_token');
        if ($base === '' || $token === '' || $keys === []) {
            return array_fill_keys($keys, null);
        }

        /** @var array<string, string|null> $out */
        $out = array_fill_keys($keys, null);

        try {
            $responses = Http::pool(function (Pool $pool) use ($keys, $base, $token): void {
                foreach ($keys as $key) {
                    $pool->as($key)
                        ->withToken($token)
                        ->timeout(5)
                        ->acceptJson()
                        ->get($base.'/get/'.rawurlencode($key));
                }
            });

            foreach ($keys as $key) {
                $res = $responses[$key] ?? null;
                if ($res === null || ! $res->ok()) {
                    continue;
                }
                $result = $res->json('result');
                if (is_string($result)) {
                    $out[$key] = $result;
                }
            }
        } catch (\Throwable) {
            return array_fill_keys($keys, null);
        }

        return $out;
    }
}
