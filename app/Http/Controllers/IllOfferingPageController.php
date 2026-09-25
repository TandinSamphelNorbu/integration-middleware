<?php

namespace App\Http\Controllers;

use App\Services\IllOfferingMappingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IllOfferingPageController extends Controller
{
    public function __construct(private IllOfferingMappingService $service) {}

    public function index(Request $request): View
    {
        $basePlanId = $request->query('base_plan_id', '109');
        $basePlanName = $request->query('base_plan_name');
        $catalog = null;
        $lookupErrors = [];

        try {
            $validated = validator([
                'base_plan_id' => $basePlanId,
                'base_plan_name' => $basePlanName,
            ], [
                'base_plan_id' => ['required', 'string', 'max:50'],
                'base_plan_name' => ['nullable', 'string', 'max:255'],
            ])->validate();
            $catalog = $this->service->getAddons($validated['base_plan_id'], $validated['base_plan_name']);
        } catch (ValidationException $exception) {
            $lookupErrors = $exception->errors();
        }

        return view('operations.ill', [
            'catalog' => $catalog,
            'basePlanId' => is_string($basePlanId) ? $basePlanId : '',
            'basePlanName' => is_string($basePlanName) ? $basePlanName : '',
            'lookupErrors' => $lookupErrors,
        ]);
    }
}
