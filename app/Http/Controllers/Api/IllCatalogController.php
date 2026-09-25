<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IllCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class IllCatalogController extends Controller
{
    public function __construct(
        private IllCatalogService $illCatalogService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'string', 'max:20'],
        ]);

        try {
            $catalog = $this->illCatalogService->getCatalog(
                $validated['service_id']
            );

            return response()->json([
                'success' => true,
                'service_id' => $validated['service_id'],
                ...$catalog,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve ILL catalog.',
            ], 500);
        }
    }
}
