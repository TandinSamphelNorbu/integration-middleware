<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CrmAuthService
{
    public function getAccessToken(): string
    {
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
        ->post(
            config('services.crm_6d.url')
            . '/ticl/api/v1/authentication'
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                'CRM authentication failed. HTTP status: '
                . $response->status()
            );
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== '200') {
            throw new RuntimeException(
                'CRM authentication failed: '
                . ($data['message'] ?? 'Unknown error')
            );
        }

        $token = $data['accesToken'] ?? null;

        if (!$token) {
            throw new RuntimeException(
                'CRM authentication response did not contain accesToken.'
            );
        }

        return $token;
    }
}
