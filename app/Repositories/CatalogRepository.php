<?php

namespace App\Repositories;

use App\Constants\CatalogConstants;
use Illuminate\Support\Facades\DB;

class CatalogRepository
{
    public function isStudentPostpaidNumber(string $serviceId): bool
    {
        return DB::connection('catalog')
            ->table('student_numbers')
            ->where('MSISDN', $serviceId)
            ->exists();
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
        if (!$has4G) {
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

                if (!$is5G) {

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

                if (!$is5G) {

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
}
