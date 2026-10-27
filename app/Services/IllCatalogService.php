<?php

namespace App\Services;

use App\Repositories\LeasedlineCatalogRepository;
use RuntimeException;

class IllCatalogService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private LeasedlineCatalogRepository $leasedlineCatalogRepository
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

        if (! in_array($crmBasePlan, ['ILL_Main_Offering', 'ILL Main Offering'], true)) {
            throw new RuntimeException(
                'Subscriber is not eligible for ILL catalog.'
            );
        }

        $addons = ['Standard' => [], 'Premium' => [], 'GIN' => []];

        foreach ($this->leasedlineCatalogRepository->getNormalOfferings() as $offering) {
            $addons[$offering['service_type']][] = $offering;
        }

        $currentPlan = $this->subscriberService->getNormalIllCurrentPlan($serviceId);
        $changes = ['upgrade' => ['Standard' => [], 'Premium' => [], 'GIN' => []], 'downgrade' => ['Standard' => [], 'Premium' => [], 'GIN' => []]];
        if ($currentPlan['state'] === 'resolved') {
            $bandwidth = $currentPlan['currentBandwidthPlan']['bandwidthMbps'];
            $serviceType = $currentPlan['currentBandwidthPlan']['serviceType'];
            foreach ($addons as $type => $offerings) {
                if ($type !== $serviceType) {
                    $addons[$type] = [];

                    continue;
                }
                foreach ($offerings as $offering) {
                    if ($offering['bandwidth'] > $bandwidth) {
                        $changes['upgrade'][$type][] = $offering;
                    } elseif ($offering['bandwidth'] < $bandwidth) {
                        $changes['downgrade'][$type][] = $offering;
                    }
                }
            }
        }

        return [
            'subscription' => $customer['subscription'] ?? null,
            'crm_base_plan' => $crmBasePlan,
            'base_plan' => ['id' => '109', 'name' => 'ILL Main Offering'],
            'addons' => $addons,
            ...$currentPlan,
            'bandwidthChangeOfferings' => $changes,
        ];
    }
}
