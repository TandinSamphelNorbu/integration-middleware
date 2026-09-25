<?php

namespace App\Http\Controllers;

use App\Models\ApiRequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function logs(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:2,3,4,5'],
            'method' => ['nullable', 'in:GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = ApiRequestLog::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search) {
                $query->where('path', 'like', '%'.$search.'%')->orWhere('request_id', $search);
            });
        }
        if (! empty($filters['status'])) {
            $query->whereBetween('status_code', [(int) $filters['status'] * 100, (int) $filters['status'] * 100 + 99]);
        }
        if (! empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }
        if (! empty($filters['date'])) {
            $query->whereDate('requested_at', $filters['date']);
        }

        return view('operations.logs', [
            'logs' => $query->orderByDesc('requested_at')->orderByDesc('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function apis(): View
    {
        $descriptions = [
            'api/v1/login' => ['Sign in', 'Exchange a username and password for a bearer token.', 'name, password'],
            'api/v1/ill/catalog' => ['ILL subscriber catalog', 'Look up optional add-ons for a subscriber on ILL_Main_Offering.', 'service_id (required)'],
            'api/v1/ill/offerings/{basePlanId}/addons' => ['ILL offering mappings', 'Read local add-on mappings for the basePlanId in the path. No subscriber lookup.', 'base_plan_name (optional; must match the stored name)'],
            'api/v1/ill/refresh' => ['Refresh ILL cache', 'Refresh the separate leased-line offerings cache. Does not update local ILL mappings.', 'None'],
            'api/v1/user' => ['Current user', 'Read the authenticated user profile.', 'None'],
            'api/v1/catalog' => ['Mobile catalog', 'Find eligible mobile plans for a subscriber.', 'service_id'],
            'api/v1/catalog/refresh' => ['Refresh catalog', 'Reload cached plans and student numbers.', 'None'],
            'api/v1/fwa/plans' => ['FWA plans', 'Find eligible 4G or 5G fixed wireless plans.', 'service_id'],
            'api/v1/fwa/sync' => ['Sync FWA plans', 'Synchronize local plans from the source catalog.', 'None'],
        ];

        $apis = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => [
                'path' => '/'.$route->uri(),
                'methods' => implode(' / ', array_diff($route->methods(), ['HEAD'])),
                'protected' => in_array('auth:sanctum', $route->gatherMiddleware(), true),
                'details' => $descriptions[$route->uri()] ?? ['API endpoint', 'Application API endpoint.', 'See endpoint validation'],
            ])->values();

        return view('operations.apis', compact('apis'));
    }
}
