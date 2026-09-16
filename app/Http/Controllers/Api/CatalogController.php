<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalogService
    ) {
    }

    public function index(CatalogRequest $request): JsonResponse
    {
        $catalog = $this->catalogService->getCatalog(
            $request->input('service_id'),
            $request->input('type')
        );

        return response()->json($catalog);
    }
}
