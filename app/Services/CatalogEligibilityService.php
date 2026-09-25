<?php

namespace App\Services;

use App\Repositories\CatalogRepository;
use RuntimeException;

class CatalogEligibilityService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private CatalogRepository $catalogRepository
    ) {}

    public function getEligibility(
        string $serviceId
    ): array {
        $customer = $this->subscriberService->getIllCustomerData($serviceId);

        if ($customer['success'] !== true) {
            throw new RuntimeException('Unable to retrieve catalog customer details.');
        }

        $type = match ($customer['subscription'] ?? null) {
            'Prepaid' => 'prepaid',
            'Postpaid' => 'postpaid',
            default => throw new RuntimeException('Unsupported subscription type for mobile catalog.'),
        };

        $primaryOfferingId =
            $this->subscriberService->getPrimaryOfferingId($serviceId);

        $has4G =
            $this->subscriberService->has4G($serviceId);

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
}
