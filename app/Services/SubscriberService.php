<?php

namespace App\Services;

use App\Constants\CrmConstants;
use App\Repositories\IllOfferingRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SubscriberService
{
    public function __construct(
        private CrmAuthService $crmAuthService,
        private IllOfferingRepository $illOfferingRepository
    ) {}

    private function queryIllService(string $serviceId)
    {
        $token = $this->crmAuthService->getAccessToken();

        $baseUrl = rtrim(
            config('services.crm_6d.url'),
            '/'
        );

        return Http::withOptions([
            'allow_redirects' => true,
        ])
            ->withHeaders([
                'Route' => 'QueryPlan',
                'sourcenode' => 'IspBillingApp',
                'Authorization' => 'Bearer '.$token,
            ])
            ->withQueryParameters([
                'type' => 2,
            ])
            ->timeout(30)
            ->send(
                'POST',
                $baseUrl.'/ticl/api/v1/passthrough/'.$serviceId
            );
    }

    private function queryIllCustomer(string $serviceId)
    {
        $token = $this->crmAuthService->getAccessToken();

        $baseUrl = rtrim(
            config('services.crm_6d.url'),
            '/'
        );

        return Http::withOptions([
            'allow_redirects' => true,
        ])
            ->withHeaders([
                'Route' => 'queryservice',
                'sourcenode' => 'MyTashiCell',
                'Authorization' => 'Bearer '.$token,
            ])
            ->withQueryParameters([
                'filter' => 'account,profile',
                'view_address' => 'true',
            ])
            ->timeout(30)
            ->send(
                'POST',
                $baseUrl.'/ticl/api/v1/passthrough/'.$serviceId
            );
    }

    public function getIllCustomerData(string $serviceId): array
    {
        $response = $this->queryIllCustomer($serviceId);

        $data = $response->json();

        /*
        * CRM may return authentication failure inside an HTTP 200 response.
        */
        $authenticationFailed =
            $response->status() === 401
            || (string) ($data['responseHeader']['responseStatus'] ?? '') === '401';

        if ($authenticationFailed) {
            $this->crmAuthService->forgetAccessToken();

            $response = $this->queryIllCustomer($serviceId);
            $data = $response->json();
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'CRM ILL customer lookup failed. HTTP status: '
                .$response->status()
            );
        }

        if ((string) ($data['result_code'] ?? '') !== '0') {
            return [
                'success' => false,
                'status' => (string) ($data['result_code'] ?? ''),
                'subscription' => null,
                'BasePlan' => null,
            ];
        }

        $subscription = null;
        $basePlan = null;

        /*
        * We only process service_info because the FWA flow needs
        * connection_type and rate_plan_name.
        *
        * No need to process profile_info or account_info.
        */
        foreach (($data['service_info']['basic_details'] ?? []) as $detail) {
            $id = (string) ($detail['id'] ?? '');

            if ($id === 'connection_type') {
                $subscription = match ((string) ($detail['value'] ?? '')) {
                    '1' => 'Postpaid',
                    '2' => 'Prepaid',
                    default => 'Hybrid',
                };
            }

            if ($id === 'rate_plan_name') {
                $basePlan = trim(
                    (string) ($detail['value'] ?? '')
                );
            }

            /*
            * Stop as soon as both required values have been found.
            */
            if ($subscription !== null && $basePlan !== null) {
                break;
            }
        }

        return [
            'success' => true,
            'status' => '0',
            'subscription' => $subscription,
            'BasePlan' => $basePlan,
        ];
    }

    public function getIllService(string $serviceId): array
    {
        $response = $this->queryIllService($serviceId);

        /*
        * CRM sometimes returns HTTP 200 with responseStatus = 401
        * inside the response body.
        */
        $data = $response->json();

        $authenticationFailed =
            $response->status() === 401
            || (string) ($data['responseHeader']['responseStatus'] ?? '') === '401';

        if ($authenticationFailed) {
            $this->crmAuthService->forgetAccessToken();

            $response = $this->queryIllService($serviceId);
            $data = $response->json();
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'CRM ILL service lookup failed. HTTP status: '
                .$response->status()
            );
        }

        /*
        * Preserve old behavior:
        * unsuccessful CRM result gives "No Active Bandwidth".
        */
        if ((string) ($data['result_code'] ?? '') !== '0') {
            return [
                'success' => false,
                'bandwidth' => 'No Active Bandwidth',
                'subscriptions' => [],
                'message' => $data['message'] ?? 'No data',
            ];
        }

        /*
        * Read the cached ILL catalog once, then index it by Id.
        *
        * This avoids repeatedly scanning the collection for every
        * CRM subscription and does not hit the old catalog DB while
        * the cache is valid.
        */
        $offeringsById = $this->illOfferingRepository
            ->getOfferings()
            ->keyBy(
                fn (array $offering) => (string) ($offering['Id'] ?? '')
            );

        $subscriptions = [];
        $bandwidth = null;

        foreach (($data['subscriptions'] ?? []) as $subscription) {
            $planId = (string) (
                $subscription['external_plan_id'] ?? ''
            );

            $planName = (string) (
                $subscription['plan_name'] ?? ''
            );

            $single = [
                'planId' => $planId,
                'planName' => $planName,
                'status' => $subscription['status'] ?? null,
            ];

            /*
            * Preserve old case-sensitive "Mbps" matching.
            */
            if (str_contains($planName, 'Mbps')) {
                $subscriptions[] = $single;

                // Old behavior: last qualifying plan wins.
                $bandwidth = $planName;

                continue;
            }

            /*
            * Fast lookup against the indexed cached offerings.
            */
            $offering = $offeringsById->get($planId);

            /*
            * Old behavior:
            * unmatched non-Mbps subscriptions are omitted.
            */
            if (! $offering) {
                continue;
            }

            $single['is_booster'] =
                $offering['is_booster'] ?? null;

            $subscriptions[] = $single;

            /*
            * Only a non-booster offering determines bandwidth.
            */
            if (($offering['is_booster'] ?? null) === 'N') {
                $bandwidth = $planName;
            }
        }

        return [
            'success' => true,
            'status' => (string) $data['result_code'],
            'subscriptions' => $subscriptions,
            'bandwidth' => $bandwidth ?? 'No Active Bandwidth',
            'message' => $bandwidth === null
                ? ($data['message'] ?? 'No data')
                : null,
        ];
    }

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
                .($data['message'] ?? $data['result_desc'] ?? 'Unknown CRM error')
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
