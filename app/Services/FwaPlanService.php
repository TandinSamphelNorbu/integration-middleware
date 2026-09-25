<?php

namespace App\Services;

use App\Constants\CatalogConstants;
use App\Models\FwaPlan;
use RuntimeException;

class FwaPlanService
{
    public function __construct(
        private SubscriberService $subscriberService
    ) {}

    public function getPlans(string $serviceId): array
    {
        /*
        * Global switch to disable FWA recharge/activation.
        * Check before CRM so no unnecessary external call is made.
        */
        if (CatalogConstants::DISABLE_FWA_RECHARGE_ACTIVATIONS) {
            throw new RuntimeException(
                'FWA Recharges and Activations are currently not allowed!'
            );
        }

        $primaryOfferingId = (int) $this->subscriberService
            ->getPrimaryOfferingId($serviceId);

        /*
        * Determine subscriber FWA type from primary offering.
        */
        if (
            $primaryOfferingId ===
            CatalogConstants::PRIMARYOFFERING_5GFWA_PREPAID
        ) {
            $planType = '5G';

        } elseif (
            $primaryOfferingId ===
            CatalogConstants::PRIMARYOFFERING_4GFWA_PREPAID
        ) {
            $planType = '4G';

        } else {
            throw new RuntimeException(
                'This number is not eligible for recharging. '
                .'Please try with valid prepaid FWA number.'
            );
        }

        $gstPercentage = CatalogConstants::GST_PERCENTAGE;

        /*
        * Fetch eligible plans from the normalized
        * local middleware table.
        */
        $plans = FwaPlan::query()
            ->where('status', true)
            ->where('plan_type', $planType)
            ->orderByDesc('amount')
            ->get()
            ->map(function ($plan) use ($gstPercentage) {
                $amount = (float) $plan->amount;

                $gstAmount = $amount * $gstPercentage;
                $totalAmount = $amount + $gstAmount;

                return [
                    'plan_name' => $plan->plan_name,
                    'amount' => $amount,
                    'data_cap' => $plan->data_cap,
                    'max_speed' => $plan->max_speed,
                    'gstAmount' => round($gstAmount, 2),
                    'totalAmount' => round($totalAmount, 2),
                ];
            })
            ->values();

        /*
        * Preserve old behavior:
        * return amounts for ALL active prepaid ILL plans,
        * regardless of 4G/5G plan type.
        */
        $prepaidILLPlans = FwaPlan::query()
            ->where('status', true)
            ->whereNotNull('amount')
            ->orderByDesc('amount')
            ->pluck('amount')
            ->map(fn ($amount) => (float) $amount)
            ->values();

        return [
            'primary_offering_id' => $primaryOfferingId,
            'plan_type' => $planType,
            'GST' => ($gstPercentage * 100).'%',
            'plans' => $plans,
            'prepaidILLPlans' => $prepaidILLPlans,
        ];
    }
}
