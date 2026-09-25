<?php

namespace App\Services;

use App\Repositories\IllOfferingRepository;

class PostpaidFwaPlanService
{
    public function __construct(
        private SubscriberService $subscriberService,
        private IllOfferingRepository $illOfferingRepository,
        private CbsFwaUsageService $cbsFwaUsageService
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

        /*
        * Preserve the exact legacy eligibility gate.
        */
        $basePlanType = match ($customerBasePlan) {
            '5G Unlimited' => '5G',
            '4G Home Unlimited Postpaid' => '4G',
            default => null,
        };

        /*
        * Alternatives and boosters are only returned when:
        *
        * 1. Customer has one of the supported BasePlans
        * 2. Current bandwidth exists in leasedlineofferings
        */
        if ($basePlanType !== null && $currentBasePlan !== null) {
            $basePlanOfferings = $this->illOfferingRepository
                ->getAlternativeBasePlans(
                    $basePlanType,
                    $subscription,
                    $bandwidth
                )
                ->values()
                ->toArray();

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
