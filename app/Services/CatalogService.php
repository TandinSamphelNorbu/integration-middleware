<?php

namespace App\Services;

use App\Repositories\CatalogRepository;

class CatalogService
{
    public function __construct(
        private CatalogEligibilityService $eligibilityService,
        private CatalogRepository $catalogRepository
    ) {}

    public function getCatalog(string $serviceId): array
    {
        $eligibility = $this->eligibilityService
            ->getEligibility($serviceId);

        $plans = $this->catalogRepository
            ->getEligiblePlansFromCache($eligibility);

        $dataPlans = $plans
            ->groupBy('CategoryId')
            ->map(function ($categoryPlans) {

                $first = $categoryPlans->first();

                return [
                    'Id' => $first->CategoryId,
                    'Category' => $first->Category,
                    'DisplayOrder' => $first->DisplayOrder,

                    'Plans' => $categoryPlans->map(function ($plan) {
                        return [
                            'Id' => $plan->Id,
                            'Name' => $plan->Name,
                            'AddOn' => $plan->AddOn,
                            'ShortName' => $plan->ShortName,
                            'Price' => $plan->Price,
                            'DataBucket' => $plan->DataBucket,
                            'Validity' => $plan->Validity,
                        ];
                    })->values()->toArray(),
                ];
            })
            ->sortBy('DisplayOrder')
            ->values()
            ->toArray();

        return [
            'poId' => $eligibility['primary_offering_id'],
            'dataPlans' => $dataPlans,
        ];
    }
}
