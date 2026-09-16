<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SubscriberService
{
    public function __construct(
        private CrmAuthService $crmAuthService
    ) {
    }

    public function getPrimaryOfferingId(string $serviceId): ?string
    {
        $token = $this->crmAuthService->getAccessToken();

        $baseUrl = rtrim(config('services.crm_6d.url'), '/');

        $response = Http::withOptions([
            'allow_redirects' => true,
        ])
        ->withHeaders([
            'Accept' => 'text/xml',
            'Content-Type' => 'application/json',
            'route' => 'QueryPlan',
            'sourcenode' => 'MyTashiCell',
            'Authorization' => 'Bearer ' . $token,
        ])
        ->withQueryParameters([
            'type' => 1,
            'network_type' => 'GSM',
        ])
        ->withBody('{}', 'application/json')
        ->timeout(30)
        ->post(
            $baseUrl . '/ticl/api/v1/passthrough/' . $serviceId
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                'CRM QueryPlan failed. HTTP status: '
                . $response->status()
                . ' Response: '
                . $response->body()
            );
        }

        $data = $response->json();

        if (($data['result_code'] ?? null) !== '0') {
            throw new RuntimeException(
                'Subscriber lookup failed: '
                . ($data['result_desc'] ?? 'Unknown CRM error')
            );
        }

        $subscriptions = $data['subscriptions'] ?? [];

        foreach ($subscriptions as $subscription) {
            if ((int) ($subscription['is_base_plan'] ?? 0) === 1) {
                return $subscription['external_plan_id'] ?? null;
            }
        }

        throw new RuntimeException(
            'No primary offering found for this service ID.'
        );
    }

    public function has4G(string $serviceId): bool
    {
        $token = $this->crmAuthService->getAccessToken();

        $baseUrl = rtrim(config('services.crm_6d.url'), '/');

        $response = Http::withOptions([
            'allow_redirects' => true,
        ])
        ->withHeaders([
            'Accept' => 'text/xml',
            'Content-Type' => 'application/json',
            'route' => 'FetchHLR',
            'sourcenode' => 'MyTashiCell',
            'Authorization' => 'Bearer ' . $token,
        ])
        ->withQueryParameters([
            'service_id' => $serviceId,
        ])
        ->withBody('{}', 'application/json')
        ->timeout(30)
        ->post(
            $baseUrl . '/ticl/api/v1/passthrough'
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                'CRM FetchHLR failed. HTTP status: '
                . $response->status()
            );
        }

        $data = $response->json();

        if (($data['result_code'] ?? null) !== '0') {
            return false;
        }

        $services = $data['hlr_services'] ?? [];

        return isset($services['4g_lte_service'])
            && $services['4g_lte_service'] !== '0';
    }
}
