<?php

namespace App\Repositories;

use App\Constants\CatalogConstants;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CatalogRepository
{
    private const CATALOG_CACHE_KEY = 'catalog.base_data';

    private const CATALOG_CACHE_TTL = 43200; // 12 hours

    private const STUDENT_NUMBERS_CACHE_KEY = 'catalog.student_numbers';

    public function isStudentPostpaidNumber(string $serviceId): bool
    {
        $studentNumbers = Cache::remember(
            self::STUDENT_NUMBERS_CACHE_KEY,
            self::CATALOG_CACHE_TTL,
            function () {
                return DB::connection('catalog')
                    ->table('student_numbers')
                    ->pluck('MSISDN')
                    ->map(fn ($number) => (string) $number)
                    ->toArray();
            }
        );

        return in_array((string) $serviceId, $studentNumbers, true);
    }

    public function getEligiblePlans(array $eligibility)
    {
        $type = $eligibility['type'];
        $poId = $eligibility['primary_offering_id'];
        $has4G = $eligibility['has_4g'];
        $isStudentPostpaid = $eligibility['is_student_postpaid'];

        $query = DB::connection('catalog')
            ->table('offerings as T1')
            ->leftJoin(
                'offeringcategory as T2',
                'T2.Id',
                '=',
                'T1.CategoryId'
            );

        /*
        * Primary Offering filtering
        */
        if ((int) $poId === CatalogConstants::PRIMARYOFFERING_LTECPE) {

            // LTE CPE subscriber:
            // Include plans specifically for LTE CPE
            // OR plans with no PO restriction.
            $query->where(function ($q) {
                $q->where(
                    'T1.ForPO',
                    CatalogConstants::PRIMARYOFFERING_LTECPE
                )
                    ->orWhere(function ($q) {
                        $q->whereNull('T1.ForPO')
                            ->whereNull('T1.NotForPO');
                    });
            });

        } else {

            $is5G = in_array((int) $poId, [
                CatalogConstants::PRIMARYOFFERING_5G_POSTPAID,
                CatalogConstants::PRIMARYOFFERING_5G_PREPAID,
            ], true);

            if ($is5G) {

                // 5G subscriber
                $query->where(function ($q) {
                    $q->where('T1.NotFor5G', '<>', 1)
                        ->orWhere(
                            'T1.Name',
                            'like',
                            '%late night 207%'
                        );
                });

            } else {

                // Normal non-LTE-CPE subscriber:
                // Include plans marked as not for LTE CPE
                // OR plans with no PO restriction.
                $query->where(function ($q) {
                    $q->where(
                        'T1.NotForPO',
                        CatalogConstants::PRIMARYOFFERING_LTECPE
                    )
                        ->orWhere(function ($q) {
                            $q->whereNull('T1.ForPO')
                                ->whereNull('T1.NotForPO');
                        });
                });
            }
        }

        /*
        * 4G filtering
        *
        * Old code:
        * if ($has4gDisabled) {
        *     AND T1.Only4G = 0
        * }
        */
        if (! $has4G) {
            $query->where('T1.Only4G', 0);
        }

        /*
        * Prepaid
        */
        if ($type === 'prepaid') {

            $query->whereIn('T1.SubscriptionType', [2, 3]);

            if (
                (int) $poId ===
                CatalogConstants::PRIMARYOFFERING_STUDENTPACK
            ) {

                $query->where(function ($q) {
                    $q->where('T1.IsStudentPlan', 1)
                        ->orWhere('T1.Type', 1)
                        ->orWhere('T1.Name', 'like', '%late%');
                });

            } else {

                $is5G = in_array((int) $poId, [
                    CatalogConstants::PRIMARYOFFERING_5G_POSTPAID,
                    CatalogConstants::PRIMARYOFFERING_5G_PREPAID,
                ], true);

                if (! $is5G) {

                    $query->where('T1.IsStudentPlan', 0)
                        ->where(function ($q) {
                            $q->where('T1.Type', 1)
                                ->orWhere(
                                    'T1.Name',
                                    'like',
                                    '%late%'
                                );
                        });

                } else {

                    /*
                    * Equivalent to old CASE:
                    *
                    * Name LIKE '%late%' -> Type IN (1,2)
                    * otherwise         -> Type = 1
                    */
                    $query->where(function ($q) {
                        $q->where(function ($q) {
                            $q->where(
                                'T1.Name',
                                'like',
                                '%late%'
                            )
                                ->whereIn('T1.Type', [1, 2]);
                        })
                            ->orWhere(function ($q) {
                                $q->where(
                                    'T1.Name',
                                    'not like',
                                    '%late%'
                                )
                                    ->where('T1.Type', 1);
                            });
                    });
                }
            }

            /*
            * Postpaid
            */
        } else {

            $query->whereIn('T1.SubscriptionType', [1, 3]);

            if ($isStudentPostpaid) {

                $query->where(function ($q) {
                    $q->where('T1.IsStudentPlan', 1)
                        ->orWhere('T1.Type', 1);
                });

            } else {

                $is5G = in_array((int) $poId, [
                    CatalogConstants::PRIMARYOFFERING_5G_POSTPAID,
                    CatalogConstants::PRIMARYOFFERING_5G_PREPAID,
                ], true);

                if (! $is5G) {

                    $query->where('T1.IsStudentPlan', 0)
                        ->where(function ($q) {
                            $q->where('T1.Type', 1)
                                ->orWhere(
                                    'T1.Name',
                                    'like',
                                    '%late%'
                                );
                        });

                } else {

                    $query->where('T1.Type', 1);
                }
            }
        }

        return $query
            ->select([
                'T1.Id',
                'T1.Name',
                'T1.AddOn',
                'T1.CategoryId',
                'T2.Name as Category',
                'T2.DisplayOrder',
                'T1.ShortName',
                DB::raw('ROUND(T1.Price, 0) as Price'),
                'T1.DataBucket',
                'T1.Validity',
            ])
            ->orderBy('T2.DisplayOrder')
            ->orderBy('T1.Price')
            ->get();
    }

    private function fetchBaseCatalog(): array
    {
        return DB::connection('catalog')
            ->table('offerings as T1')
            ->leftJoin(
                'offeringcategory as T2',
                'T2.Id',
                '=',
                'T1.CategoryId'
            )
            ->select([
                'T1.Id',
                'T1.Name',
                'T1.AddOn',
                'T1.CategoryId',
                'T2.Name as Category',
                'T2.DisplayOrder',
                'T1.ShortName',
                DB::raw('ROUND(T1.Price, 0) as Price'),
                'T1.DataBucket',
                'T1.Validity',
                'T1.SubscriptionType',
                'T1.Type',
                'T1.IsStudentPlan',
                'T1.Only4G',
                'T1.ForPO',
                'T1.NotForPO',
                'T1.NotFor5G',
            ])
            ->orderBy('T2.DisplayOrder')
            ->orderBy('T1.Price')
            ->get()
            ->map(fn ($item) => (array) $item)
            ->toArray();
    }

    private function getBaseCatalog()
    {
        $catalog = Cache::remember(
            self::CATALOG_CACHE_KEY,
            self::CATALOG_CACHE_TTL,
            fn () => $this->fetchBaseCatalog()
        );

        return collect($catalog);
    }

    public function getEligiblePlansFromCache(array $eligibility)
    {
        $plans = $this->getBaseCatalog();

        $poId = (int) $eligibility['primary_offering_id'];
        $type = strtolower($eligibility['type']);
        $has4G = $eligibility['has_4g'];
        $isStudentPostpaid = $eligibility['is_student_postpaid'];

        return $plans->filter(function ($plan) use (
            $poId,
            $type,
            $has4G,
            $isStudentPostpaid
        ) {

            // -------------------------------------------------
            // 1. Primary Offering eligibility
            // -------------------------------------------------

            if ($poId === CatalogConstants::PRIMARYOFFERING_LTECPE) {

                $allowed =
                    (string) $plan['ForPO'] ===
                        (string) CatalogConstants::PRIMARYOFFERING_LTECPE
                    ||
                    (
                        $plan['ForPO'] === null &&
                        $plan['NotForPO'] === null
                    );

                if (! $allowed) {
                    return false;
                }

            } else {

                $is5G = in_array($poId, [
                    CatalogConstants::PRIMARYOFFERING_5G_POSTPAID,
                    CatalogConstants::PRIMARYOFFERING_5G_PREPAID,
                ], true);

                if ($is5G) {

                    $notFor5G = $plan['NotFor5G'];

                    $allowed =
                        ($notFor5G !== null && (int) $notFor5G !== 1)
                        ||
                        str_contains(
                            strtolower($plan['Name'] ?? ''),
                            'late night 207'
                        );

                    if (! $allowed) {
                        return false;
                    }

                } else {

                    $allowed =
                        (string) $plan['NotForPO'] ===
                            (string) CatalogConstants::PRIMARYOFFERING_LTECPE
                        ||
                        (
                            $plan['ForPO'] === null &&
                            $plan['NotForPO'] === null
                        );

                    if (! $allowed) {
                        return false;
                    }
                }
            }

            // -------------------------------------------------
            // 2. 4G eligibility
            // -------------------------------------------------

            if (! $has4G && (int) $plan['Only4G'] !== 0) {
                return false;
            }

            // -------------------------------------------------
            // 3. Prepaid eligibility
            // -------------------------------------------------

            if ($type === 'prepaid') {

                // SubscriptionType IN (2,3)
                if (! in_array(
                    (int) $plan['SubscriptionType'],
                    [2, 3],
                    true
                )) {
                    return false;
                }

                // Student prepaid
                if (
                    $poId ===
                    CatalogConstants::PRIMARYOFFERING_STUDENTPACK
                ) {

                    // IsStudentPlan = 1
                    // OR Type = 1
                    // OR Name LIKE '%late%'

                    $allowed =
                        (int) $plan['IsStudentPlan'] === 1
                        ||
                        (int) $plan['Type'] === 1
                        ||
                        str_contains(
                            strtolower($plan['Name'] ?? ''),
                            'late'
                        );

                    if (! $allowed) {
                        return false;
                    }

                } else {

                    $is5G = in_array($poId, [
                        CatalogConstants::PRIMARYOFFERING_5G_POSTPAID,
                        CatalogConstants::PRIMARYOFFERING_5G_PREPAID,
                    ], true);

                    // Normal prepaid
                    if (! $is5G) {

                        // IsStudentPlan = 0
                        // AND (Type = 1 OR Name LIKE '%late%')

                        if ((int) $plan['IsStudentPlan'] !== 0) {
                            return false;
                        }

                        $allowed =
                            (int) $plan['Type'] === 1
                            ||
                            str_contains(
                                strtolower($plan['Name'] ?? ''),
                                'late'
                            );

                        if (! $allowed) {
                            return false;
                        }

                    } else {

                        // 5G prepaid:
                        // If name contains "late":
                        //     Type IN (1,2)
                        // Otherwise:
                        //     Type = 1

                        $isLate = str_contains(
                            strtolower($plan['Name'] ?? ''),
                            'late'
                        );

                        if ($isLate) {

                            if (! in_array(
                                (int) $plan['Type'],
                                [1, 2],
                                true
                            )) {
                                return false;
                            }

                        } else {

                            if ((int) $plan['Type'] !== 1) {
                                return false;
                            }
                        }
                    }
                }
            }

            // -------------------------------------------------
            // 4. Postpaid eligibility
            // -------------------------------------------------

            elseif ($type === 'postpaid') {

                // Old SQL:
                // SubscriptionType IN (1,3)

                if (! in_array(
                    (int) $plan['SubscriptionType'],
                    [1, 3],
                    true
                )) {
                    return false;
                }

                // Student postpaid
                if ($isStudentPostpaid) {

                    // Old SQL:
                    // IsStudentPlan = 1
                    // OR Type = 1

                    $allowed =
                        (int) $plan['IsStudentPlan'] === 1
                        ||
                        (int) $plan['Type'] === 1;

                    if (! $allowed) {
                        return false;
                    }

                } else {

                    $is5G = in_array($poId, [
                        CatalogConstants::PRIMARYOFFERING_5G_POSTPAID,
                        CatalogConstants::PRIMARYOFFERING_5G_PREPAID,
                    ], true);

                    // Normal non-5G postpaid
                    if (! $is5G) {

                        // Old SQL:
                        // IsStudentPlan = 0
                        // AND (Type = 1 OR Name LIKE '%late%')

                        if ((int) $plan['IsStudentPlan'] !== 0) {
                            return false;
                        }

                        $allowed =
                            (int) $plan['Type'] === 1
                            ||
                            str_contains(
                                strtolower($plan['Name'] ?? ''),
                                'late'
                            );

                        if (! $allowed) {
                            return false;
                        }

                    } else {

                        // Old 5G postpaid rule:
                        // Type = 1

                        if ((int) $plan['Type'] !== 1) {
                            return false;
                        }
                    }
                }
            }

            return true;

        })
            ->map(fn ($plan) => (object) $plan)
            ->values();
    }

    public function refreshCatalogCache(): array
    {
        // Fetch everything first.
        // If either DB query fails, the existing cache remains untouched.
        $baseCatalog = $this->fetchBaseCatalog();

        $studentNumbers = DB::connection('catalog')
            ->table('student_numbers')
            ->pluck('MSISDN')
            ->map(fn ($number) => (string) $number)
            ->toArray();

        // Only replace cache after both DB operations succeed.
        Cache::put(
            self::CATALOG_CACHE_KEY,
            $baseCatalog,
            self::CATALOG_CACHE_TTL
        );

        Cache::put(
            self::STUDENT_NUMBERS_CACHE_KEY,
            $studentNumbers,
            self::CATALOG_CACHE_TTL
        );

        return [
            'plans_cached' => count($baseCatalog),
            'student_numbers_cached' => count($studentNumbers),
        ];
    }
}
