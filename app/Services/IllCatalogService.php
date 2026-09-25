<?php

namespace App\Services;

use RuntimeException;

class IllCatalogService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private IllOfferingMappingService $illOfferingMappingService
    ) {}

    public function getCatalog(string $serviceId): array
    {
        $customer = $this->subscriberService
            ->getIllCustomerData($serviceId);

        if (($customer['success'] ?? false) !== true) {
            throw new RuntimeException(
                'Unable to retrieve ILL customer details.'
            );
        }

        $crmBasePlan = $customer['BasePlan'] ?? null;

        if ($crmBasePlan !== 'ILL_Main_Offering') {
            throw new RuntimeException(
                'Subscriber is not eligible for ILL catalog.'
            );
        }

        /*
         * Confirmed mapping:
         *
         * CRM:   ILL_Main_Offering
         * Local: 109 / ILL Main Offering
         */
        $catalog = $this->illOfferingMappingService->getAddons(
            '109',
            'ILL Main Offering'
        );

        return [
            'subscription' => $customer['subscription'] ?? null,
            'crm_base_plan' => $crmBasePlan,
            'base_plan' => $catalog['base_plan'],
            'addons' => $catalog['addons'],
        ];
    }
}
