<?php

namespace App\Services;

use App\Repositories\CatalogRepository;

class CatalogEligibilityService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private CatalogRepository $catalogRepository
    ) {
    }

    public function getEligibility(
        string $serviceId,
        string $type
    ): array {
        $type = strtolower($type);

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
