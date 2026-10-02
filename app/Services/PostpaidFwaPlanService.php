<?php

namespace App\Services;

use App\Repositories\IllOfferingRepository;
use App\Repositories\LeasedlineCatalogRepository;

class PostpaidFwaPlanService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private IllOfferingRepository $illOfferingRepository,
        private CbsFwaUsageService $cbsFwaUsageService,
        private LeasedlineCatalogRepository $leasedlineCatalogRepository
    ) {}

    /**
     * @param  array{success: bool, status?: string, subscription: ?string, BasePlan: ?string}|null  $customer
     */
    public function getPlans(string $serviceId, ?array $customer = null): array
    {
        $customer ??= $this->subscriberService
            ->getIllCustomerData($serviceId);

        if (! $customer['success']) {
            throw new \RuntimeException(
                'Unable to retrieve ILL customer details.'
            );
        }

        if (($customer['subscription'] ?? null) !== 'Postpaid') {
            throw new \RuntimeException('Subscriber is not eligible for postpaid FWA catalog.');
        }

        $service = $this->subscriberService
            ->getIllService($serviceId);

        $usage = $this->cbsFwaUsageService->getUsage($serviceId);
        $subscription = $customer['subscription'];
        $customerBasePlan = $customer['BasePlan'];
        $bandwidth = $service['bandwidth'];

        /*
        * Legacy current-plan lookup:
        *
        * leasedlineofferings.Name = CRM bandwidth
        *
        * Our cached offering already contains the joined
        * leasedlineofferingsdtls fields.
        */
        $currentBasePlan = $this->illOfferingRepository
            ->getCurrentPlan($bandwidth);

        /*
        * Legacy defaults.
        */
        $basePlanOfferings = false;
        $addOnOfferings = false;

        $category = match ($customerBasePlan) {
            '5G Unlimited' => '5G ILL',
            '4G Home Unlimited Postpaid' => '4G ILL',
            default => null,
        };

        if ($category !== null) {
            $basePlanOfferings = $this->leasedlineCatalogRepository
                ->getPostpaidFwaOfferings($category)
                ->reject(fn (array $offering): bool => $offering['Name'] === $bandwidth)
                ->values()->all();
        }

        if ($category !== null && $currentBasePlan !== null) {
            $addOnOfferings = $this->illOfferingRepository
                ->getBoosterPlans($subscription)
                ->values()
                ->toArray();
        }

        return [
            'success' => true,
            'subscription' => $subscription,
            'BasePlan' => $customerBasePlan,
            'bandwidth' => $bandwidth,
            'subscriptions' => $service['subscriptions'],
            'currentBasePlan' => $currentBasePlan,
            'basePlanOfferings' => $basePlanOfferings,
            'addOnOfferings' => $addOnOfferings,
            'usage' => $usage,
        ];
    }
}
