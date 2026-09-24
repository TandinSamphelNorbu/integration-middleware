<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalogService,
        private CatalogRepository $catalogRepository
    ) {}

    public function index(CatalogRequest $request): JsonResponse
    {
        try {
            $catalog = $this->catalogService->getCatalog(
                $request->input('service_id'),
                $request->input('type')
            );

            return response()->json($catalog);

        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function refresh(): JsonResponse
    {
        try {
            $result = $this->catalogRepository
                ->refreshCatalogCache();

            return response()->json([
                'success' => true,
                'message' => 'Catalog cache refreshed successfully.',
                'plans_cached' => $result['plans_cached'],
                'student_numbers_cached' => $result['student_numbers_cached'],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Catalog cache refresh failed.',
            ], 500);
        }
    }
}
