<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CrmAuthService
{
    public function getAccessToken(): string
    {
        $cachedToken = Cache::get('crm_6d_access_token');

        if ($cachedToken) {
            return $cachedToken;
        }

        $url = rtrim(config('services.crm_6d.url'), '/');

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml; charset=utf-8',
            ])
                ->withBody(
                    json_encode([
                        'userName' => config('services.crm_6d.username'),
                        'password' => config('services.crm_6d.password'),
                    ]),
                    'text/xml; charset=utf-8'
                )
                ->timeout(30)
                ->post($url.'/ticl/api/v1/authentication');
        } catch (ConnectionException) {
            Log::error('CRM connection failed.', [
                'operation' => 'Authentication',
                'host' => parse_url($url, PHP_URL_HOST),
                'failure_type' => 'connection',
            ]);

            throw new RuntimeException('Unable to connect to CRM for authentication. Please try again later.');
        }

        if (! $response->successful()) {
            Log::error('CRM request failed.', [
                'operation' => 'Authentication',
                'status_code' => $response->status(),
                'failure_type' => 'http',
            ]);
            throw new RuntimeException(
                'CRM authentication failed. HTTP status: '.$response->status()
            );
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== '200') {
            throw new RuntimeException(
                $data['message'] ?? 'CRM authentication failed'
            );
        }

        $token = $data['accesToken'] ?? null;

        if (! $token) {
            throw new RuntimeException(
                'CRM authentication response missing accesToken'
            );
        }

        $ttl = $this->getTokenTtl($token);

        Cache::put('crm_6d_access_token', $token, $ttl);

        return $token;
    }

    private function getTokenTtl(string $token): int
    {
        try {
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                return 3000;
            }

            $payload = strtr($parts[1], '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

            $decoded = json_decode(
                base64_decode($payload),
                true
            );

            $expiresAt = $decoded['exp'] ?? null;

            if (! $expiresAt) {
                return 3000;
            }

            // Expire our cached copy 60 seconds before CRM's JWT expires.
            return max(60, $expiresAt - time() - 60);

        } catch (\Throwable $e) {
            // Conservative fallback: 50 minutes.
            return 3000;
        }
    }

    public function forgetAccessToken(): void
    {
        Cache::forget('crm_6d_access_token');
    }
}
