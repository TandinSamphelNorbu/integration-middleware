<?php

namespace App\Services;

use RuntimeException;

class FwaPlanResolver
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private SubscriberService $subscriberService,
        private FwaPlanService $fwaPlanService,
        private PostpaidFwaPlanService $postpaidFwaPlanService
    ) {}

    public function getPlans(string $serviceId): array
    {
        $customer = $this->subscriberService->getIllCustomerData($serviceId);

        if ($customer['success'] !== true) {
            throw new RuntimeException('Unable to retrieve FWA customer details.');
        }

        return match ($customer['subscription'] ?? null) {
            'Prepaid' => $this->fwaPlanService->getPlans($serviceId),
            'Postpaid' => $this->postpaidFwaPlanService->getPlans($serviceId, $customer),
            default => throw new RuntimeException('Unsupported subscription type for FWA.'),
        };
    }
}
