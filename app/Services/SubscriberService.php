<?php

namespace App\Services;

use App\Constants\CrmConstants;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SubscriberService
{
    public function __construct(
        private CrmAuthService $crmAuthService
    ) {}

    public function getPrimaryOfferingId(string $serviceId): ?string
    {
        $response = $this->queryPlan($serviceId);

        // If cached token is rejected, clear it and retry ONCE
        if ($response->status() === 401) {
            $this->crmAuthService->forgetAccessToken();

            $response = $this->queryPlan($serviceId);
        }

        if (! $response->successful()) {
            Log::error('CRM request failed.', [
                'operation' => 'QueryPlan',
                'status_code' => $response->status(),
                'failure_type' => 'http',
            ]);
            throw new RuntimeException(
                'CRM QueryPlan failed. HTTP status: '.$response->status()
            );
        }

        $data = $response->json();
        if (($data['result_code'] ?? null) !== '0') {
            throw new RuntimeException(
                'Subscriber lookup failed: '
                .($data['message'] ?? $data['result_desc'] ??  'Unknown CRM error')
            );
        }

        foreach (($data['subscriptions'] ?? []) as $subscription) {
            if ((int) ($subscription['is_base_plan'] ?? 0) === 1) {
                $primaryOfferingId = $subscription['external_plan_id'] ?? null;

                if (! $primaryOfferingId) {
                    throw new RuntimeException(
                        'Primary offering ID missing for subscriber.'
                    );
                }

                return $primaryOfferingId;
            }
        }

        throw new RuntimeException(
            'No primary offering found for this service ID.'
        );
    }

    private function queryPlan(string $serviceId): Response
    {
        $token = $this->crmAuthService->getAccessToken();
        $baseUrl = rtrim(config('services.crm_6d.url'), '/');

        try {
            return Http::withOptions([
                'allow_redirects' => true,
            ])
                ->withHeaders([
                    'Accept' => 'text/xml',
                    'Content-Type' => 'application/json',
                    'route' => 'QueryPlan',
                    'sourcenode' => CrmConstants::SOURCE_NODE,
                    'Authorization' => 'Bearer '.$token,
                ])
                ->withQueryParameters([
                    'type' => 1,
                    'network_type' => 'GSM',
                ])
                ->withBody('{}', 'application/json')
                ->timeout(30)
                ->post(
                    $baseUrl.'/ticl/api/v1/passthrough/'.$serviceId
                );
        } catch (ConnectionException) {
            Log::error('CRM connection failed.', [
                'operation' => 'QueryPlan',
                'host' => parse_url($baseUrl, PHP_URL_HOST),
                'failure_type' => 'connection',
            ]);

            throw new RuntimeException('Unable to connect to CRM for QueryPlan. Please try again later.');
        }
    }

    public function has4G(string $serviceId): bool
    {
        $response = $this->fetchHLR($serviceId);

        // If cached token is rejected, clear it and retry ONCE
        if ($response->status() === 401) {
            $this->crmAuthService->forgetAccessToken();

            $response = $this->fetchHLR($serviceId);
        }

        if (! $response->successful()) {
            Log::error('CRM request failed.', [
                'operation' => 'FetchHLR',
                'status_code' => $response->status(),
                'failure_type' => 'http',
            ]);
            throw new RuntimeException(
                'CRM FetchHLR failed. HTTP status: '.$response->status()
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

    private function fetchHLR(string $serviceId): Response
    {
        $token = $this->crmAuthService->getAccessToken();
        $baseUrl = rtrim(config('services.crm_6d.url'), '/');

        try {
            return Http::withOptions([
                'allow_redirects' => true,
            ])
                ->withHeaders([
                    'Accept' => 'text/xml',
                    'Content-Type' => 'application/json',
                    'route' => 'FetchHLR',
                    'sourcenode' => CrmConstants::SOURCE_NODE,
                    'Authorization' => 'Bearer '.$token,
                ])
                ->withQueryParameters([
                    'service_id' => $serviceId,
                ])
                ->withBody('{}', 'application/json')
                ->timeout(30)
                ->post($baseUrl.'/ticl/api/v1/passthrough');
        } catch (ConnectionException) {
            Log::error('CRM connection failed.', [
                'operation' => 'FetchHLR',
                'host' => parse_url($baseUrl, PHP_URL_HOST),
                'failure_type' => 'connection',
            ]);

            throw new RuntimeException('Unable to connect to CRM for FetchHLR. Please try again later.');
        }
    }
}
