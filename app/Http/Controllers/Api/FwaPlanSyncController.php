<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FwaPlanSyncService;
use Illuminate\Http\JsonResponse;

class FwaPlanSyncController extends Controller
{
    public function __construct(
        private FwaPlanSyncService $fwaPlanSyncService
    ) {}

    public function sync(): JsonResponse
    {
        try {
            $result = $this->fwaPlanSyncService->sync();

            return response()->json([
                'success' => true,
                'message' => 'FWA plans synchronized successfully.',
                'plans_synced' => $result['plans_synced'],
                'synced_at' => $result['synced_at'],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'FWA plan synchronization failed.',
            ], 500);
        }
    }
}
