<?php

namespace App\Services;

use App\Repositories\IllOfferingMappingRepository;
use Illuminate\Validation\ValidationException;

class IllOfferingMappingService
{
    public function __construct(private IllOfferingMappingRepository $repository) {}

    /** @return array{base_plan: array{id: string, name: ?string}, addons: list<array{id: string, name: string}>} */
    public function getAddons(string $basePlanId, ?string $basePlanName = null): array
    {
        $storedName = $this->repository->getBasePlanName($basePlanId);

        if ($storedName !== null && $basePlanName !== null && $basePlanName !== $storedName) {
            throw ValidationException::withMessages([
                'base_plan_name' => 'The base plan name does not match the supplied base plan ID.',
            ]);
        }

        return [
            'base_plan' => ['id' => $basePlanId, 'name' => $storedName ?? $basePlanName],
            'addons' => $this->repository->getAddonsForBasePlan($basePlanId),
        ];
    }

    /** @return array{id: string, name: string}|null */
    public function findAddon(string $basePlanId, string $addonId): ?array
    {
        return $this->repository->findAddon($basePlanId, $addonId);
    }
}
