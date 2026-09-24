<?php

namespace App\Services;

use App\Models\FwaPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FwaPlanSyncService
{
    /**
     * Read and normalize FWA plans from the legacy catalog database.
     *
     * This method does NOT modify the local middleware database.
     */
    public function fetchSourcePlans(): Collection
    {
        $plans = DB::connection('catalog')
            ->table('leasedlineofferings_prepaid')
            ->get();

        $details = DB::connection('catalog')
            ->table('leasedlineofferingsdtls')
            ->get();

        return $plans->map(function ($plan) use ($details) {

            /*
             * Preserve the legacy relationship:
             *
             * leasedlineofferingsdtls.price
             * =
             * "Nu. " + leasedlineofferings_prepaid.amount
             */
            $expectedPrice = 'Nu. '.$plan->amount;

            $detail = $details->first(function ($item) use ($expectedPrice) {
                return trim((string) $item->price) === $expectedPrice;
            });

            if (! $detail) {
                throw new RuntimeException(
                    "No FWA detail record found for source plan ID {$plan->id} "
                    ."with amount {$plan->amount}."
                );
            }

            $planType = strtoupper(trim((string) $plan->plan_type));

            if (! in_array($planType, ['4G', '5G'], true)) {
                throw new RuntimeException(
                    "Invalid FWA plan type '{$plan->plan_type}' "
                    ."for source plan ID {$plan->id}."
                );
            }

            return [
                'source_id' => (int) $plan->id,
                'crm_id' => $plan->crm_id !== null
                    ? (int) $plan->crm_id
                    : null,

                'cbs_id' => $plan->cbs_id !== null
                    ? (int) $plan->cbs_id
                    : null,

                'plan_name' => trim((string) $plan->plan_name),
                'plan_type' => $planType,
                'amount' => $plan->amount,

                'data_cap' => $detail->data_cap !== null
                    ? trim((string) $detail->data_cap)
                    : null,

                'max_speed' => $detail->max_speed !== null
                    ? trim((string) $detail->max_speed)
                    : null,

                'default_speed' => $detail->default_speed !== null
                    ? trim((string) $detail->default_speed)
                    : null,

                'status' => (int) $plan->status === 1,
            ];
        });
    }

    public function sync(): array
    {
        // Fetch and validate everything from the old DB first.
        // If this fails, the existing local data remains untouched.
        $sourcePlans = $this->fetchSourcePlans();

        if ($sourcePlans->isEmpty()) {
            throw new RuntimeException(
                'No FWA plans were returned from the source database.'
            );
        }

        $syncedAt = now();

        DB::transaction(function () use ($sourcePlans, $syncedAt) {

            $sourceIds = $sourcePlans
                ->pluck('source_id')
                ->toArray();

            foreach ($sourcePlans as $plan) {
                FwaPlan::updateOrCreate(
                    [
                        'source_id' => $plan['source_id'],
                    ],
                    [
                        'crm_id' => $plan['crm_id'],
                        'cbs_id' => $plan['cbs_id'],
                        'plan_name' => $plan['plan_name'],
                        'plan_type' => $plan['plan_type'],
                        'amount' => $plan['amount'],
                        'data_cap' => $plan['data_cap'],
                        'max_speed' => $plan['max_speed'],
                        'default_speed' => $plan['default_speed'],
                        'status' => $plan['status'],
                        'source_synced_at' => $syncedAt,
                    ]
                );
            }

            /*
            * If a plan disappears from the source table,
            * don't delete it from middleware history.
            * Mark it inactive instead.
            */
            FwaPlan::whereNotIn('source_id', $sourceIds)
                ->update([
                    'status' => false,
                    'source_synced_at' => $syncedAt,
                ]);
        });

        return [
            'plans_synced' => $sourcePlans->count(),
            'synced_at' => $syncedAt->toDateTimeString(),
        ];
    }
}
