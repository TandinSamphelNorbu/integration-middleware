<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PostpaidFwaPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PostpaidFwaPlanController extends Controller
{
    public function __construct(
        private PostpaidFwaPlanService $postpaidFwaPlanService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'string', 'max:20'],
        ]);

        try {
            $result = $this->postpaidFwaPlanService
                ->getPlans($validated['service_id']);

            return response()->json([
                'success' => true,
                'service_id' => $validated['service_id'],
                'subscription' => $result['subscription'],
                'BasePlan' => $result['BasePlan'],
                'bandwidth' => $result['bandwidth'],
                'subscriptions' => $result['subscriptions'],
                'currentBasePlan' => $result['currentBasePlan'],
                'basePlanOfferings' => $result['basePlanOfferings'],
                'addOnOfferings' => $result['addOnOfferings'],
                'usage' => $result['usage'],
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
