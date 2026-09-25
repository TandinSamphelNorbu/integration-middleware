<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IllOfferingMappingRequest;
use App\Services\IllOfferingMappingService;
use Illuminate\Http\JsonResponse;

class IllOfferingMappingController extends Controller
{
    public function __construct(private IllOfferingMappingService $service) {}

    public function index(IllOfferingMappingRequest $request, string $basePlanId): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->service->getAddons($basePlanId, $request->validated('base_plan_name')),
        ]);
    }
}
