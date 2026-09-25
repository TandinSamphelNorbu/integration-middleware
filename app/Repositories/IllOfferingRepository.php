<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IllOfferingRepository
{
    private const CACHE_KEY = 'ill.offerings';

    private const CACHE_TTL = 43200; // 12 hours

    /**
     * Get ILL offerings from cache.
     * Old database is queried only when cache is missing/expired.
     */
    public function getCurrentPlan(string $bandwidth): ?array
    {
        return $this->getOfferings()
            ->first(
                fn (array $offering) => (string) ($offering['Name'] ?? '') === $bandwidth
            );
    }

    public function getAlternativeBasePlans(
        string $basePlanType,
        string $subscription,
        string $currentBandwidth
    ): Collection {
        return $this->getOfferings()
            ->filter(function (array $offering) use (
                $basePlanType,
                $subscription,
                $currentBandwidth
            ) {
                return ($offering['base_plan'] ?? null) === $basePlanType
                    && ($offering['sim_type'] ?? null) === $subscription
                    && ($offering['status'] ?? null) === 'new'
                    && ($offering['Name'] ?? null) !== $currentBandwidth;
            })
            ->values();
    }

    public function getOfferings(): Collection
    {
        $offerings = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => $this->fetchOfferings()
        );

        return collect($offerings);
    }

    public function getOfferingsBySimType(string $simType): Collection
    {
        return $this->getOfferings()
            ->filter(function ($offering) use ($simType) {
                return strtolower($offering['sim_type'] ?? '')
                    === strtolower($simType);
            })
            ->values();
    }

    public function getBasePlans(string $basePlan): Collection
    {
        $basePlan = strtoupper($basePlan);

        return $this->getOfferings()
            ->filter(function ($offering) use ($basePlan) {
                return ($offering['status'] ?? null) === 'new'
                    && ($offering['sim_type'] ?? null) === 'Postpaid'
                    && ($offering['is_booster'] ?? null) === 'N'
                    && strtoupper($offering['base_plan'] ?? '') === $basePlan;
            })
            ->sortBy(function ($offering) {
                preg_match(
                    '/(\d+)_Postpaid$/i',
                    $offering['Name'] ?? '',
                    $matches
                );

                return isset($matches[1])
                    ? (int) $matches[1]
                    : PHP_INT_MAX;
            })
            ->values();
    }

    public function getBoosterPlans(string $subscription): Collection
    {
        return $this->getOfferings()
            ->filter(function (array $offering) use ($subscription) {
                return ($offering['is_booster'] ?? null) === 'Y'
                    && ($offering['sim_type'] ?? null) === $subscription
                    && ($offering['status'] ?? null) === 'new';
            })
            ->values();
    }

    public function findOfferingById(string|int $offeringId): ?array
    {
        return $this->getOfferings()
            ->first(function (array $offering) use ($offeringId) {
                return (string) ($offering['Id'] ?? '') ===
                    (string) $offeringId;
            });
    }

    /**
     * Fetch fresh offerings from old database.
     */
    private function fetchOfferings(): array
    {
        return DB::connection('catalog')
            ->table('leasedlineofferings as T1')
            ->leftJoin(
                'leasedlineofferingsdtls as T2',
                'T2.leasedline_id',
                '=',
                'T1.Id'
            )
            ->select([
                'T1.Id',
                'T1.Name',
                'T1.sim_type',
                'T1.base_plan',
                'T1.is_booster',
                'T1.status',

                'T2.price',
                'T2.data_cap',
                'T2.max_speed',
                'T2.default_speed',
            ])
            ->get()
            ->map(fn ($item) => (array) $item)
            ->values()
            ->toArray();
    }

    /**
     * Force refresh the 12-hour cache.
     */
    public function refreshCache(): array
    {
        // Fetch first so existing cache remains if source DB fails.
        $offerings = $this->fetchOfferings();

        Cache::put(
            self::CACHE_KEY,
            $offerings,
            self::CACHE_TTL
        );

        return [
            'offerings_cached' => count($offerings),
        ];
    }

    /**
     * Remove the cached ILL offerings.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
