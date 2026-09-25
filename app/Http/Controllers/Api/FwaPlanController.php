<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FwaPlanResolver;
use App\Services\FwaPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FwaPlanController extends Controller
{
    public function __construct(
        private FwaPlanService $fwaPlanService,
        private FwaPlanResolver $fwaPlanResolver
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'string', 'max:20'],
        ]);

        try {
            $result = $this->fwaPlanResolver->getPlans($validated['service_id']);

            return response()->json([
                'success' => true,
                'service_id' => $validated['service_id'],
                ...$result,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function prepaid(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'string', 'max:20'],
        ]);

        try {
            $result = $this->fwaPlanService->getPlans(
                $validated['service_id']
            );

            return response()->json([
                'success' => true,
                'primary_offering_id' => $result['primary_offering_id'],
                'plan_type' => $result['plan_type'],
                'GST' => $result['GST'],
                'plans' => $result['plans'],
                'prepaidILLPlans' => $result['prepaidILLPlans'],
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
