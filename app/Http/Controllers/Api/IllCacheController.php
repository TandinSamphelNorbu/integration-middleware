<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\IllOfferingRepository;
use Illuminate\Http\JsonResponse;

class IllCacheController extends Controller
{
    public function __construct(
        private IllOfferingRepository $illOfferingRepository
    ) {}

    public function refresh(): JsonResponse
    {
        try {
            $result = $this->illOfferingRepository
                ->refreshCache();

            return response()->json([
                'success' => true,
                'message' => 'ILL offerings cache refreshed successfully.',
                'offerings_cached' => $result['offerings_cached'],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'ILL offerings cache refresh failed.',
            ], 500);
        }
    }
}
