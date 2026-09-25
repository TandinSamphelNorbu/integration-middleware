<?php

namespace App\Services;

use App\Repositories\CatalogRepository;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

class CatalogEligibilityService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private CatalogRepository $catalogRepository
    ) {}

    public function getEligibility(
        string $serviceId
    ): array {
        $customer = $this->lookup($serviceId, 'queryservice', 'Unable to retrieve catalog customer details.', function () use ($serviceId): array {
            $customer = $this->subscriberService->getIllCustomerData($serviceId);

            if ($customer['success'] !== true) {
                throw new IntegrationException('CRM returned an unsuccessful customer lookup.', 'queryservice', $customer['http_status'] ?? null);
            }

            return $customer;
        });

        $type = match ($customer['subscription'] ?? null) {
            'Prepaid' => 'prepaid',
            'Postpaid' => 'postpaid',
            default => throw new RuntimeException('Unsupported subscription type for mobile catalog.'),
        };

        $primaryOfferingId = $this->lookup($serviceId, 'QueryPlan', 'Unable to retrieve subscriber primary offering.',
            fn () => $this->subscriberService->getPrimaryOfferingId($serviceId));

        $has4G = $this->lookup($serviceId, 'FetchHLR', 'Unable to retrieve subscriber network details.',
            fn () => $this->subscriberService->has4G($serviceId));

        /*
         * The old system checks student_numbers only
         * for postpaid subscribers.
         *
         * Prepaid student eligibility is determined
         * later using PRIMARYOFFERING_STUDENTPACK.
         */
        $isStudentPostpaid = false;

        if ($type === 'postpaid') {
            $isStudentPostpaid =
                $this->catalogRepository
                    ->isStudentPostpaidNumber($serviceId);
        }

        return [
            'service_id' => $serviceId,
            'type' => $type,
            'primary_offering_id' => $primaryOfferingId,
            'has_4g' => $has4G,
            'is_student_postpaid' => $isStudentPostpaid,
        ];
    }

    private function lookup(string $serviceId, string $operation, string $message, callable $lookup): mixed
    {
        try {
            return $lookup();
        } catch (Throwable $exception) {
            $cause = $exception;
            $status = null;

            do {
                if ($cause instanceof RequestException) {
                    $status = $cause->response->status();
                    break;
                }
            } while ($cause = $cause->getPrevious());

            $failure = $exception instanceof IntegrationException
                ? $exception
                : new IntegrationException($message, $operation, $status, $exception);
            $failure->logFailure($serviceId);

            throw new RuntimeException($message, 0, $exception);
        }
    }
}
